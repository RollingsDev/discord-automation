export function onlyDigits(value) {
  return String(value ?? "").replace(/\D/g, "");
}

export function isValidCnpj(value) {
  const digits = onlyDigits(value);

  if (digits.length !== 14 || /^(\d)\1{13}$/.test(digits)) {
    return false;
  }

  const calculate = base => {
    let factor = base.length - 7;
    let sum = 0;

    for (const char of base) {
      sum += Number(char) * factor--;
      if (factor < 2) factor = 9;
    }

    const remainder = sum % 11;
    return remainder < 2 ? 0 : 11 - remainder;
  };

  const first = calculate(digits.slice(0, 12));
  const second = calculate(digits.slice(0, 12) + first);

  return digits.endsWith(`${first}${second}`);
}

export function formatCnpj(value) {
  const digits = onlyDigits(value);

  if (digits.length !== 14) return String(value ?? "").trim();

  return digits.replace(
    /^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/,
    "$1.$2.$3/$4-$5"
  );
}

export function filenameCnpj(value) {
  return formatCnpj(value).replace("/", ".");
}

export function sanitizeFilename(value) {
  return String(value ?? "")
    .replace(/[<>:"/\\|?*\u0000-\u001F]/g, " ")
    .replace(/\s+/g, " ")
    .replace(/[. ]+$/g, "")
    .trim()
    .slice(0, 170);
}

export function companyBaseFilename(entity) {
  return sanitizeFilename(
    `${filenameCnpj(entity.cnpj)} - ${entity.name}`
  );
}

export function isoNow() {
  return new Date().toISOString();
}

export function sleep(ms) {
  return new Promise(resolve => setTimeout(resolve, ms));
}
