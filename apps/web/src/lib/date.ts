export function parseNigerianDateInput(value: string): string | null {
  const match = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(value.trim());
  if (!match) return null;
  const [, day, month, year] = match;
  const iso = `${year}-${month}-${day}`;
  const date = new Date(`${iso}T00:00:00Z`);
  return date.getUTCFullYear() === Number(year) && date.getUTCMonth() + 1 === Number(month) && date.getUTCDate() === Number(day) ? iso : null;
}

const WAT_FORMATTER = new Intl.DateTimeFormat("en-NG", {
  timeZone: "Africa/Lagos",
  day: "numeric",
  month: "short",
  year: "numeric",
  hour: "numeric",
  minute: "2-digit",
  hour12: true,
});

export function formatWatTimestamp(timestamp: string): string {
  return `${WAT_FORMATTER.format(new Date(timestamp))} WAT`;
}
