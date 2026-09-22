/**
 * The one shared copy of the public, non-secret growth curve — must stay byte-for-byte
 * equivalent to BirdEscapeEngine::multiplierHundredthsAtElapsedMs() (apps/platform
 * app/Domain/Games/Engine/BirdEscape/BirdEscapeEngine.php) so the client's displayed
 * multiplier and the server's authoritative one never silently drift apart. Only the
 * crash POINT is secret; this curve is public and identical on both sides.
 *
 * Floor is 100 (1.00x) at t<=0 — flight always starts at break-even, never 0.00x (a
 * crash game's multiplier can never read below what an instant cash-out would return).
 * From there, a constant/uniform rate: every full 1.00x step takes the same duration
 * (growthRateConstant ms, default 10000ms: 1.00x to 2.00x takes 10 seconds).
 */
export function multiplierHundredthsAtElapsedMs(elapsedMs: number, growthRateConstant: number = 10000): number {
  if (elapsedMs <= 0) return 100;

  const rate = Math.max(1, growthRateConstant);
  return 100 + Math.floor((Math.floor(elapsedMs) * 100) / rate);
}
