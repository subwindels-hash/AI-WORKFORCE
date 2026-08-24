export type SeoSettings = { siteName: string; title: string; description: string; keywords: string[]; siteUrl: string; ogImage: string; robots: string };

const rawKeywords = process.env.NEXT_PUBLIC_SITE_KEYWORDS ?? "lead discovery,sales intelligence,business leads,lead pipeline";
export const seoSettings: SeoSettings = {
  siteName: process.env.NEXT_PUBLIC_SITE_NAME ?? "Scout Lead Intelligence",
  title: process.env.NEXT_PUBLIC_SITE_TITLE ?? "Scout Lead Intelligence",
  description: process.env.NEXT_PUBLIC_SITE_DESCRIPTION ?? "Discover, organize, and prioritize real business leads.",
  keywords: rawKeywords.split(",").map(value => value.trim()).filter(Boolean),
  siteUrl: (process.env.NEXT_PUBLIC_SITE_URL ?? "http://localhost:3000").replace(/\/$/, ""),
  ogImage: process.env.NEXT_PUBLIC_OG_IMAGE ?? "/images/scout-logo.png",
  robots: process.env.NEXT_PUBLIC_ROBOTS ?? "index,follow",
};
