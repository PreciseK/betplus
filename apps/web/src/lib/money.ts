const WHOLE_NAIRA_FORMATTER = new Intl.NumberFormat("en-NG", {
  maximumFractionDigits: 0,
  useGrouping: true,
});

export type KoboDisplayPrecision = "auto" | "always";

function assertIntegerKobo(amountKobo: number) {
  if (!Number.isSafeInteger(amountKobo)) {
    throw new TypeError("Money must be supplied as a safe integer number of kobo.");
  }
}

export function formatKobo(
  amountKobo: number,
  precision: KoboDisplayPrecision = "auto",
): string {
  assertIntegerKobo(amountKobo);

  const sign = amountKobo < 0 ? "−" : "";
  const absoluteKobo = Math.abs(amountKobo);
  const wholeNaira = Math.floor(absoluteKobo / 100);
  const remainingKobo = absoluteKobo % 100;
  const fraction = precision === "always" || remainingKobo > 0
    ? `.${String(remainingKobo).padStart(2, "0")}`
    : "";

  return `${sign}₦${WHOLE_NAIRA_FORMATTER.format(wholeNaira)}${fraction}`;
}

export function formatKoboForInput(amountKobo: number): string {
  assertIntegerKobo(amountKobo);

  const sign = amountKobo < 0 ? "-" : "";
  const absoluteKobo = Math.abs(amountKobo);
  const wholeNaira = Math.floor(absoluteKobo / 100);
  const remainingKobo = absoluteKobo % 100;

  return remainingKobo > 0
    ? `${sign}${wholeNaira}.${String(remainingKobo).padStart(2, "0")}`
    : `${sign}${wholeNaira}`;
}

export function parseNairaInputToKobo(rawValue: string): number | null {
  const normalized = rawValue.replace(/[\s,]/g, "");
  if (!normalized) return null;
  if (!/^\d+(?:\.\d{0,2})?$/.test(normalized)) return null;

  const [wholePart, fractionPart = ""] = normalized.split(".");
  const wholeNaira = Number(wholePart);
  const remainingKobo = Number(fractionPart.padEnd(2, "0"));
  const amountKobo = wholeNaira * 100 + remainingKobo;

  return Number.isSafeInteger(amountKobo) ? amountKobo : null;
}
