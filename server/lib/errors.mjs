export class DashlessError extends Error {
  constructor(code, message, details = undefined) {
    super(message);
    this.name = "DashlessError";
    this.code = code;
    this.details = details;
  }
}

export function asDashlessError(error) {
  if (error instanceof DashlessError) return error;
  return new DashlessError("internal_error", error?.message || String(error));
}

export function requireString(value, name, { allowEmpty = false } = {}) {
  if (typeof value !== "string" || (!allowEmpty && value.trim() === "")) {
    throw new DashlessError("invalid_input", `${name} must be a non-empty string.`);
  }
  return value;
}

export function requireInteger(value, name) {
  if (!Number.isInteger(value) || value < 1) {
    throw new DashlessError("invalid_input", `${name} must be a positive integer.`);
  }
  return value;
}
