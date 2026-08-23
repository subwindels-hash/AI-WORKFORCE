export function parseAllowedOrigins(value = process.env.CORS_ORIGINS ?? ""): string[] {
  return value.split(",").map(origin => origin.trim().replace(/\/$/, "")).filter(Boolean);
}
export function requireProductionConfig(): void {
  if (process.env.NODE_ENV === "production" && parseAllowedOrigins().length === 0) throw new Error("CORS_ORIGINS must list the allowed web origins in production");
}
