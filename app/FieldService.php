<?php
declare(strict_types=1);

/**
 * Dynamic fields (metadata registry).
 *  - Loading definitions for form generation and validation.
 *  - Server-side validation and normalization of attributes against the definitions.
 *  - Definition management (to be used by a future admin module; the methods are ready).
 */
final class FieldService
{
    private const DATA_TYPES = ['text', 'textarea', 'number', 'integer', 'boolean', 'date', 'select', 'multiselect', 'hierarchy', 'photos', 'files'];

    /** Field types with attachments (value = list [{id,name,size}]). */
    public const ATTACHMENT_TYPES = ['photos', 'files'];

    /** Save context for attachment verification (who is saving and which feature). */
    private ?int $ctxFeatureId = null;
    private int $ctxUserId = 0;

    /** Required system field "Name" – the admin may not edit, remove or move it. */
    public const PROTECTED_KEY = 'nazev';

    /** Active definitions for the frontend (sorted). */
    public function getActiveDefinitions(): array
    {
        $rows = Database::pdo()
            ->query('SELECT * FROM field_definitions WHERE is_active = 1 ORDER BY sort_order, id')
            ->fetchAll();
        return array_map([$this, 'shapeDefinition'], $rows);
    }

    /** All definitions (for admin management). */
    public function getAllDefinitions(): array
    {
        $rows = Database::pdo()
            ->query('SELECT * FROM field_definitions ORDER BY sort_order, id')
            ->fetchAll();
        return array_map([$this, 'shapeDefinition'], $rows);
    }

    /**
     * Validates and normalizes attributes against the active definitions.
     * Drops unknown keys and converts values to the correct type.
     * @throws HttpException 422 with per-field details
     */
    public function validateAttributes(array $attributes, string $geometryType, ?int $featureId = null, int $userId = 0): array
    {
        $this->ctxFeatureId = $featureId;
        $this->ctxUserId = $userId;
        $defs = $this->getActiveDefinitions();
        $clean = [];
        $errors = [];

        foreach ($defs as $def) {
            $key = $def['field_key'];

            // Field applies only to certain geometry types?
            if (is_array($def['applies_to']) && !in_array($geometryType, $def['applies_to'], true)) {
                continue;
            }

            // Hierarchical field (cascade) – values are stored under the keys of the individual levels (e.g. area, type, variant)
            if ($def['data_type'] === 'hierarchy') {
                $levels = $def['validation']['levels'] ?? [];
                if (!is_array($levels) || count($levels) === 0) {
                    $levels = [['key' => $key, 'label' => $def['label']]];
                }

                $currentNodes = is_array($def['options']) ? $def['options'] : [];
                $levelValues = [];

                foreach ($levels as $lvl) {
                    $lvlKey = (string) ($lvl['key'] ?? '');
                    $lvlLabel = (string) ($lvl['label'] ?? $lvlKey);
                    $lvlVal = $attributes[$lvlKey] ?? null;

                    if ($this->isEmpty($lvlVal)) {
                        if ($def['is_required']) {
                            $errors[] = [
                                'field' => $lvlKey,
                                'code' => 'field_required',
                                'params' => ['label' => $lvlLabel],
                                'message' => 'Field "' . $lvlLabel . '" is required.'
                            ];
                        }
                        $currentNodes = [];
                        continue;
                    }

                    $sVal = trim((string) $lvlVal);
                    $matchedNode = null;
                    foreach ($currentNodes as $node) {
                        if (($node['value'] ?? '') === $sVal || ($node['label'] ?? '') === $sVal) {
                            $matchedNode = $node;
                            break;
                        }
                    }

                    if ($matchedNode === null) {
                        $errors[] = [
                            'field' => $lvlKey,
                            'code' => 'field_value_not_allowed',
                            'params' => ['label' => $lvlLabel],
                            'message' => 'Field "' . $lvlLabel . '" has a disallowed value.'
                        ];
                        $currentNodes = [];
                    } else {
                        $clean[$lvlKey] = (string) ($matchedNode['value'] ?? $sVal);
                        $levelValues[] = (string) ($matchedNode['label'] ?? $matchedNode['value'] ?? $sVal);
                        $currentNodes = isset($matchedNode['children']) && is_array($matchedNode['children']) ? $matchedNode['children'] : [];
                    }
                }

                if ($key !== '' && !isset($clean[$key]) && count($levelValues) > 0) {
                    $clean[$key] = implode(' > ', $levelValues);
                }

                continue;
            }

            $value = $attributes[$key] ?? null;

            if ($this->isEmpty($value)) {
                if ($def['is_required']) {
                    $errors[] = ['field' => $key, 'code' => 'field_required', 'params' => ['label' => $def['label']], 'message' => 'Field "' . $def['label'] . '" is required.'];
                }
                continue; // an empty optional field is not stored
            }

            try {
                $clean[$key] = $this->coerce($value, $def);
            } catch (HttpException $e) {
                $item = ['field' => $key, 'code' => $e->errorCode, 'message' => $e->getMessage()];
                if ($e->params) {
                    $item['params'] = $e->params;
                }
                $errors[] = $item;
            }
        }

        if ($errors) {
            throw new HttpException('The form contains errors.', 422, 'validation', $errors);
        }
        return $clean;
    }

    // ---------------------------------------------------- normalization -----

    /** @param mixed $value @return mixed */
    private function coerce($value, array $def)
    {
        $label = $def['label'];
        $rules = is_array($def['validation']) ? $def['validation'] : [];

        switch ($def['data_type']) {
            case 'text':
            case 'textarea':
                if (!is_scalar($value)) {
                    $this->fail('field_must_be_text', "Field \"{$label}\" must be text.", ['label' => $label]);
                }
                $s = trim((string) $value);
                $this->checkLength($s, $rules, $label);
                $this->checkPattern($s, $rules, $label);
                return $s;

            case 'number':
                if (!is_numeric($value)) {
                    $this->fail('field_must_be_number', "Field \"{$label}\" must be a number.", ['label' => $label]);
                }
                return $this->checkRange((float) $value, $rules, $label);

            case 'integer':
                if (is_bool($value) || !is_numeric($value) || (float) $value != (int) (float) $value) {
                    $this->fail('field_must_be_integer', "Field \"{$label}\" must be a whole number.", ['label' => $label]);
                }
                return (int) $this->checkRange((float) (int) $value, $rules, $label);

            case 'boolean':
                $b = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($b === null) {
                    $this->fail('field_must_be_boolean', "Field \"{$label}\" must be yes/no.", ['label' => $label]);
                }
                return $b;

            case 'date':
                if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                    $this->fail('field_must_be_date', "Field \"{$label}\" must be a date (YYYY-MM-DD).", ['label' => $label]);
                }
                [$y, $mo, $d] = array_map('intval', explode('-', $value));
                if (!checkdate($mo, $d, $y)) {
                    $this->fail('field_invalid_date', "Field \"{$label}\" contains an invalid date.", ['label' => $label]);
                }
                return $value;

            case 'select':
                $allowed = $this->optionValues($def);
                if (!is_scalar($value) || !in_array((string) $value, $allowed, true)) {
                    $this->fail('field_value_not_allowed', "Field \"{$label}\" has a disallowed value.", ['label' => $label]);
                }
                return (string) $value;

            case 'hierarchy':
                return is_scalar($value) ? (string) $value : $value;

            case 'photos':
            case 'files':
                return $this->coerceAttachments($value, $def);

            case 'multiselect':
                if (!is_array($value)) {
                    $this->fail('field_must_be_list', "Field \"{$label}\" must be a list.", ['label' => $label]);
                }
                $allowed = $this->optionValues($def);
                $out = [];
                foreach ($value as $v) {
                    if (!is_scalar($v) || !in_array((string) $v, $allowed, true)) {
                        $this->fail('field_contains_value_not_allowed', "Field \"{$label}\" contains a disallowed value.", ['label' => $label]);
                    }
                    $out[] = (string) $v;
                }
                return array_values(array_unique($out));
        }

        $this->fail('field_unknown_type', "Field \"{$label}\" has an unknown type.", ['label' => $label]);
    }

    /**
     * Attachment list: verifies that each id exists, belongs to this field and may be used
     * (it already belongs to this feature, or the saving user just uploaded it and it isn't linked yet).
     * Returns the canonical form [{id,name,size}] from the DB – client-supplied names/sizes are not trusted.
     */
    private function coerceAttachments($value, array $def): array
    {
        $label = $def['label'];
        if (!is_array($value)) {
            $this->fail('field_must_be_list', "Field \"{$label}\" must be a list.", ['label' => $label]);
        }
        $ids = [];
        foreach ($value as $item) {
            $id = is_array($item) ? ($item['id'] ?? null) : $item;
            if (!is_numeric($id) || (int) $id <= 0) {
                $this->fail('field_attachment_invalid', "Field \"{$label}\" contains an invalid file.", ['label' => $label]);
            }
            $ids[] = (int) $id;
        }
        $ids = array_values(array_unique($ids));
        $rules = is_array($def['validation']) ? $def['validation'] : [];
        $max = (int) ($rules['maxFiles'] ?? AttachmentService::DEFAULT_MAX_FILES);
        if ($max > 0 && count($ids) > $max) {
            $this->fail('field_too_many_files', "Field \"{$label}\" has too many files (max {$max}).", ['label' => $label, 'max' => $max]);
        }

        $rows = (new AttachmentService())->findMany($ids);
        $out = [];
        foreach ($ids as $id) {
            $r = $rows[$id] ?? null;
            $ok = $r !== null && $r['field_key'] === $def['field_key'] && (
                ($this->ctxFeatureId !== null && (int) $r['feature_id'] === $this->ctxFeatureId)
                || ($r['feature_id'] === null && (int) $r['created_by'] === $this->ctxUserId)
            );
            if (!$ok) {
                $this->fail('field_attachment_invalid', "Field \"{$label}\" contains an invalid file.", ['label' => $label]);
            }
            $out[] = ['id' => $id, 'name' => (string) $r['original_name'], 'size' => (int) $r['size_bytes']];
        }
        return $out;
    }

    /** Ids of all attachments in the (validated) attributes – to link to the feature after saving. */
    public function attachmentIds(array $clean): array
    {
        $ids = [];
        foreach ($this->getActiveDefinitions() as $def) {
            if (in_array($def['data_type'], self::ATTACHMENT_TYPES, true) && is_array($clean[$def['field_key']] ?? null)) {
                foreach ($clean[$def['field_key']] as $item) {
                    $ids[] = (int) $item['id'];
                }
            }
        }
        return $ids;
    }

    private function checkLength(string $s, array $rules, string $label): void
    {
        if (isset($rules['maxLength']) && mb_strlen($s) > (int) $rules['maxLength']) {
            $this->fail('field_too_long', "Field \"{$label}\" is too long (max {$rules['maxLength']} characters).", ['label' => $label, 'max' => (int) $rules['maxLength']]);
        }
    }

    private function checkPattern(string $s, array $rules, string $label): void
    {
        if (!isset($rules['pattern']) || $rules['pattern'] === '') {
            return;
        }
        // Full match of the whole value (not just a substring).
        $regex = '/^(?:' . str_replace('/', '\/', (string) $rules['pattern']) . ')$/u';
        if (@preg_match($regex, $s) !== 1) {
            $this->fail('field_bad_format', "Field \"{$label}\" has an invalid format.", ['label' => $label]);
        }
    }

    private function checkRange(float $n, array $rules, string $label): float
    {
        if (isset($rules['min']) && $n < (float) $rules['min']) {
            $this->fail('field_min', "Field \"{$label}\" must be at least {$rules['min']}.", ['label' => $label, 'min' => $rules['min']]);
        }
        if (isset($rules['max']) && $n > (float) $rules['max']) {
            $this->fail('field_max', "Field \"{$label}\" must be at most {$rules['max']}.", ['label' => $label, 'max' => $rules['max']]);
        }
        return $n;
    }

    /** @return string[] */
    private function optionValues(array $def): array
    {
        $values = [];
        if (is_array($def['options'])) {
            foreach ($def['options'] as $opt) {
                if (is_array($opt) && isset($opt['value'])) {
                    $values[] = (string) $opt['value'];
                }
            }
        }
        return $values;
    }

    /** @param mixed $value */
    private function isEmpty($value): bool
    {
        return $value === null
            || $value === ''
            || (is_array($value) && count($value) === 0);
    }

    // ---------------------------------------------- definition management ---

    public function createDefinition(array $input): int
    {
        $data = $this->validateDefinition($input, null);
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO field_definitions
                (field_key, label, help_text, data_type, options, is_required, validation, applies_to, default_value, sort_order, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute($data['params']);
        return (int) $pdo->lastInsertId();
    }

    public function updateDefinition(int $id, array $input): void
    {
        $currentKey = $this->keyById($id);
        if ($currentKey === self::PROTECTED_KEY) {
            throw new HttpException('The "Object name" field is required and cannot be edited.', 422, 'field_protected_edit');
        }
        // The field key is IMMUTABLE after creation: collected data in features.attributes is stored under
        // the original key, so renaming the key would "orphan" it (it would stop being displayed
        // and validated, and on the next save it would be dropped as unknown). The label can be changed freely.
        if ($currentKey !== null && isset($input['field_key']) && (string) $input['field_key'] !== $currentKey) {
            throw new HttpException('A field key cannot be changed after creation (edit the display name via the label).', 422, 'field_key_immutable');
        }
        if ($currentKey !== null) {
            $input['field_key'] = $currentKey; // safeguard in case the client doesn't send the key at all
        }
        $data = $this->validateDefinition($input, $id);
        $stmt = Database::pdo()->prepare(
            'UPDATE field_definitions SET
                field_key = ?, label = ?, help_text = ?, data_type = ?, options = ?,
                is_required = ?, validation = ?, applies_to = ?, default_value = ?, sort_order = ?, is_active = ?
             WHERE id = ?'
        );
        $params = $data['params'];
        $params[] = $id;
        $stmt->execute($params);
    }

    /** "Deletion" = deactivation, so historical data is preserved. */
    public function deactivateDefinition(int $id): void
    {
        if ($this->keyById($id) === self::PROTECTED_KEY) {
            throw new HttpException('The "Object name" field is required and cannot be removed.', 422, 'field_protected_remove');
        }
        Database::pdo()
            ->prepare('UPDATE field_definitions SET is_active = 0 WHERE id = ?')
            ->execute([$id]);
    }

    /**
     * Sets the field order according to the given ids (index = sort_order). The protected field
     * "nazev" is always first (sort_order 0); unknown ids are ignored.
     * @param int[] $ids
     */
    public function reorder(array $ids): void
    {
        Database::transaction(function (PDO $pdo) use ($ids) {
            $pdo->prepare('UPDATE field_definitions SET sort_order = 0 WHERE field_key = ?')
                ->execute([self::PROTECTED_KEY]);
            $stmt = $pdo->prepare('UPDATE field_definitions SET sort_order = ? WHERE id = ? AND field_key <> ?');
            $i = 1;
            foreach ($ids as $id) {
                $id = (int) $id;
                if ($id <= 0) {
                    continue;
                }
                $stmt->execute([$i, $id, self::PROTECTED_KEY]);
                $i++;
            }
        });
    }

    /** Returns the field_key of the given definition (or null). */
    private function keyById(int $id): ?string
    {
        $stmt = Database::pdo()->prepare('SELECT field_key FROM field_definitions WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $key = $stmt->fetchColumn();
        return $key !== false ? (string) $key : null;
    }

    /** Validates a field definition and prepares parameters for SQL. */
    private function validateDefinition(array $in, ?int $existingId): array
    {
        $key = (string) ($in['field_key'] ?? '');
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key)) {
            throw new HttpException('A field key must be a-z, 0-9, _ and start with a letter.', 422, 'field_key_format');
        }
        $label = trim((string) ($in['label'] ?? ''));
        if ($label === '') {
            throw new HttpException('The field label is required.', 422, 'field_label_required');
        }
        $dataType = (string) ($in['data_type'] ?? 'text');
        if (!in_array($dataType, self::DATA_TYPES, true)) {
            throw new HttpException('Invalid field type.', 422, 'field_type_invalid');
        }

        // Normalize select field options to {value,label} and verify their shape.
        $options = null;
        if (in_array($dataType, ['select', 'multiselect'], true)) {
            $raw = $in['options'] ?? null;
            if (!is_array($raw) || count($raw) === 0) {
                throw new HttpException('A choice field must have at least one option.', 422, 'select_needs_option');
            }
            $options = [];
            foreach ($raw as $opt) {
                if (is_array($opt) && isset($opt['value']) && is_scalar($opt['value'])) {
                    $val = (string) $opt['value'];
                    $lbl = isset($opt['label']) && is_scalar($opt['label']) ? (string) $opt['label'] : $val;
                } elseif (is_scalar($opt)) {
                    $val = (string) $opt;
                    $lbl = $val;
                } else {
                    throw new HttpException('Every option must have a value.', 422, 'option_value_missing');
                }
                if ($val === '') {
                    throw new HttpException('An option value must not be empty.', 422, 'option_value_empty');
                }
                $options[] = ['value' => $val, 'label' => $lbl];
            }
        }

        if ($dataType === 'hierarchy') {
            $rawLevels = $in['validation']['levels'] ?? null;
            if (!is_array($rawLevels) || count($rawLevels) < 2) {
                throw new HttpException('A hierarchical field must define at least 2 levels.', 422, 'hierarchy_needs_levels');
            }
            $levels = [];
            foreach ($rawLevels as $lvl) {
                $lKey = trim((string) ($lvl['key'] ?? ''));
                $lLabel = trim((string) ($lvl['label'] ?? ''));
                if ($lKey === '' || $lLabel === '') {
                    throw new HttpException('All hierarchy levels must have a key and a label.', 422, 'level_invalid');
                }
                $levels[] = ['key' => $lKey, 'label' => $lLabel];
            }
            $rawOptions = $in['options'] ?? null;
            if (!is_array($rawOptions) || count($rawOptions) === 0) {
                throw new HttpException('A hierarchical field must have an options tree.', 422, 'hierarchy_needs_options');
            }
            $options = $this->normalizeHierarchyNodes($rawOptions);
            if (count($options) === 0) {
                throw new HttpException('Options tree cannot be empty.', 422, 'hierarchy_options_empty');
            }
            $validation = is_array($in['validation'] ?? null) ? $in['validation'] : [];
            $validation['levels'] = $levels;
        }

        // Verify validation rules (numeric bounds and regular expression).
        $validation = isset($validation) && is_array($validation) ? $validation : (isset($in['validation']) && is_array($in['validation']) ? $in['validation'] : null);
        if ($validation !== null) {
            foreach (['min', 'max', 'maxLength', 'maxFiles'] as $nk) {
                if (isset($validation[$nk]) && !is_numeric($validation[$nk])) {
                    throw new HttpException("Rule \"{$nk}\" must be a number.", 422, 'rule_must_be_number', [], ['rule' => $nk]);
                }
            }
            if (isset($validation['pattern'])) {
                if (!is_string($validation['pattern'])) {
                    throw new HttpException('Rule "pattern" must be text.', 422, 'pattern_must_be_text');
                }
                if ($validation['pattern'] !== ''
                    && @preg_match('/^(?:' . str_replace('/', '\/', $validation['pattern']) . ')$/u', '') === false) {
                    throw new HttpException('Invalid regular expression in the "pattern" rule.', 422, 'pattern_invalid');
                }
            }
        }

        // Key uniqueness check.
        $stmt = Database::pdo()->prepare('SELECT id FROM field_definitions WHERE field_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        if ($row && (int) $row['id'] !== (int) $existingId) {
            throw new HttpException('A field with this key already exists.', 422, 'field_key_exists');
        }

        $appliesTo = $in['applies_to'] ?? null;
        if (is_array($appliesTo)) {
            $appliesTo = array_values(array_intersect($appliesTo, ['point', 'line', 'polygon']));
            if (count($appliesTo) === 0) {
                $appliesTo = null;
            }
        } else {
            $appliesTo = null;
        }

        return [
            'params' => [
                $key,
                $label,
                isset($in['help_text']) && $in['help_text'] !== '' ? (string) $in['help_text'] : null,
                $dataType,
                $options !== null ? json_encode($options, JSON_UNESCAPED_UNICODE) : null,
                !empty($in['is_required']) ? 1 : 0,
                $validation !== null ? json_encode($validation, JSON_UNESCAPED_UNICODE) : null,
                $appliesTo !== null ? json_encode($appliesTo, JSON_UNESCAPED_UNICODE) : null,
                array_key_exists('default_value', $in) ? json_encode($in['default_value'], JSON_UNESCAPED_UNICODE) : null,
                (int) ($in['sort_order'] ?? 0),
                array_key_exists('is_active', $in) ? (!empty($in['is_active']) ? 1 : 0) : 1,
            ],
        ];
    }

    // --------------------------------------------------------- helpers -------

    private function shapeDefinition(array $row): array
    {
        return [
            'id'            => (int) $row['id'],
            'field_key'     => $row['field_key'],
            'label'         => $row['label'],
            'help_text'     => $row['help_text'],
            'data_type'     => $row['data_type'],
            'options'       => $this->decode($row['options']),
            'is_required'   => (int) $row['is_required'] === 1,
            'validation'    => $this->decode($row['validation']),
            'applies_to'    => $this->decode($row['applies_to']),
            'default_value' => $this->decode($row['default_value']),
            'sort_order'    => (int) $row['sort_order'],
            'is_active'     => (int) $row['is_active'] === 1,
        ];
    }

    /** @return mixed */
    private function decode($raw)
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $decoded = json_decode((string) $raw, true);
        return $decoded;
    }

    private function fail(string $code, string $message, array $params = []): void
    {
        throw new HttpException($message, 422, $code, [], $params);
    }

    /**
     * Recursively normalizes and checks the nodes of a hierarchy tree.
     * @return array<array{value: string, label: string, children?: array}>
     */
    private function normalizeHierarchyNodes(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $n) {
            if (!is_array($n)) {
                continue;
            }
            $val = trim((string) ($n['value'] ?? $n['label'] ?? ''));
            if ($val === '') {
                continue;
            }
            $lbl = trim((string) ($n['label'] ?? $val));
            $item = ['value' => $val, 'label' => $lbl];
            if (isset($n['children']) && is_array($n['children']) && count($n['children']) > 0) {
                $children = $this->normalizeHierarchyNodes($n['children']);
                if (count($children) > 0) {
                    $item['children'] = $children;
                }
            }
            $out[] = $item;
        }
        return $out;
    }
}
