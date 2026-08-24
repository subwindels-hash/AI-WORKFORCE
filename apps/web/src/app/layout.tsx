import "./globals.css";
import type { Metadata } from "next";
import { AssistantWidget } from "../components/assistant/AssistantWidget";

export const metadata: Metadata = {
  title: { default: "Scout Lead Intelligence", template: "%s · Scout" },
  description: "Discover, organize, and prioritize real business leads.",
  icons: { icon: "/images/scout-favicon.png", shortcut: "/images/scout-favicon.png", apple: "/images/scout-favicon.png" },
};

export default function RootLayout({ children }: Readonly<{ children: React.ReactNode }>) { return <html lang="en"><body>{children}<AssistantWidget /></body></html>; }
