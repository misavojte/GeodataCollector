-- -----------------------------------------------------------------------------
--  Migration: Layer linking (parent_id)
--
--  1. Extends the map_layers table:
--     - parent_id: reference to the parent layer.
--                  A sub-layer is not shown separately in the client panel;
--                  it is toggled and controlled together with its parent layer.
--
--  Idempotent (ADD COLUMN IF NOT EXISTS).
--  Apply:  mysql -u <user> -p <db> < db/migrate_layer_parent.sql
-- -----------------------------------------------------------------------------

-- 1. Table schema change
ALTER TABLE map_layers
    ADD COLUMN IF NOT EXISTS parent_id INT UNSIGNED NULL AFTER sort_order;

-- Add the foreign key and index (if not present yet)
SET @fk_exists = (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'map_layers'
      AND constraint_name = 'fk_map_layers_parent'
);

SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE map_layers ADD KEY idx_map_layers_parent (parent_id), ADD CONSTRAINT fk_map_layers_parent FOREIGN KEY (parent_id) REFERENCES map_layers (id) ON DELETE CASCADE',
    'SELECT "FK fk_map_layers_parent already exists"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
