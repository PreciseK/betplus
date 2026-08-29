export function normalizeNigerianPhone(value: string): string | null {
  const digits = value.replace(/\D/g, "");
  if (/^0[789][01]\d{8}$/.test(digits)) return `+234${digits.slice(1)}`;
  if (/^234[789][01]\d{8}$/.test(digits)) return `+${digits}`;
  return null;
}

export function maskNigerianPhone(e164: string): string {
  const local = e164.replace(/^\+234/, "0");
  return `${local.slice(0, 4)} ••• •${local.slice(-3)}`;
}
