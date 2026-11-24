// One-time detection of "reduce motion" (prefers-reduced-motion) on the client.
// Shared by animations across components (modals, …). During SSR/prerender matchMedia is
// unavailable → false (animations on); at runtime in the browser it is evaluated correctly.
export const reducedMotion =
    typeof matchMedia !== 'undefined' && matchMedia('(prefers-reduced-motion: reduce)').matches;
