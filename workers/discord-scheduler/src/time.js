export const TIMEZONE = "America/Sao_Paulo";

export function saoPauloParts(date = new Date()) {
  const formatter = new Intl.DateTimeFormat("en-CA", {
    timeZone: TIMEZONE,
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
    hourCycle: "h23"
  });

  const parts = Object.fromEntries(
    formatter.formatToParts(date).map(part => [part.type, part.value])
  );

  return {
    date: `${parts.year}-${parts.month}-${parts.day}`,
    brDate: `${parts.day}/${parts.month}/${parts.year}`,
    hour: Number(parts.hour),
    minute: Number(parts.minute)
  };
}

export function formatHour(iso) {
  return new Intl.DateTimeFormat("pt-BR", {
    timeZone: TIMEZONE,
    hour: "2-digit",
    minute: "2-digit",
    hourCycle: "h23"
  }).format(new Date(iso));
}

export function formatDateBr(isoDate) {
  const [year, month, day] = String(isoDate).split("-");
  return year && month && day ? `${day}/${month}/${year}` : isoDate;
}

export function weekdayPt(isoDate) {
  const date = new Date(`${isoDate}T12:00:00-03:00`);
  const names = [
    "domingo",
    "segunda-feira",
    "terça-feira",
    "quarta-feira",
    "quinta-feira",
    "sexta-feira",
    "sábado"
  ];

  return names[date.getUTCDay()] ?? "";
}