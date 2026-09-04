import { NextResponse } from "next/server";

const localGuide = (message: string) => {
  const value = message.toLowerCase();
  if (value.includes("language") || value.includes("teacher")) return "AI Language Teacher is at /app/languages/teacher; My Languages is /app/languages. Use lessons, translation, listening, speaking practice, and SRS vocabulary. Speaking scores use real transcripts and are never invented.";
  if (value.includes("lead") || value.includes("discover")) return "Lead Discovery is at /leads and Pipeline is at /lead-pipeline. Search real businesses through a configured provider; empty results are never filled with fake leads. Review duplicates and export stored records from Intelligence.";
  if (value.includes("sport")) return "Sports Intel is at /sports. Fixtures and odds appear only from connected providers; the platform does not invent matches or odds.";
  if (value.includes("lottery") || value.includes("euromillion")) return "EuroMillions is at /lottery. Statistics, systems, tickets, and backtests use historical or explicitly labelled data. Official draws require a configured feed and analysis is not a prediction.";
  if (value.includes("trading") || value.includes("paper") || value.includes("broker")) return "Trading starts in ANALYSIS_ONLY with the kill switch active. Use /paper for simulation, /strategy for research, /execution for governed routing, /brokers for connectors, and /risk for limits. Agents never bypass risk controls or place broker orders directly.";
  if (value.includes("multiplier") || value.includes("crash") || value.includes("aviator")) return "Multiplier AI is at /multiplier. It analyses live crash history from Bustabit or the configured real provider. If the feed is unavailable it shows NO_DATA; simulated rounds are not substituted.";
  if (value.includes("agent") || value.includes("workforce")) return "Windels AI Agents are at /app/workforce, with platform health at /app/agent-platform. Agents cover market, sports, lottery, language, trading, leads, and video. Tool actions require approval and are audited.";
  if (value.includes("dashboard") || value.includes("home")) return "Dashboard is at /dashboard and shows the platform overview. Widgets stay empty when their real provider is unavailable; the system does not invent results.";
  if (value.includes("account") || value.includes("profile") || value.includes("password")) return "Manage your profile and security at /account. Alerts are at /notifications and support messages are at /messages.";
  return "I am WINDELS Assistant for AI-WORKFORCE. I can explain Dashboard, Windels AI Agents, AI Language Teacher, Languages, Lead Discovery, Pipeline, Sports, EuroMillions, Trading, Multiplier AI, Risk, Brokers, and Account. Tell me which area you want to use and I will give the exact path and real-data rules.";
};

export async function POST(request: Request) {
  const input = await request.json().catch(() => ({})) as { message?: unknown; history?: unknown };
  if (typeof input.message !== "string" || input.message.trim().length < 1 || input.message.length > 1000) return NextResponse.json({ error: "message must contain 1–1000 characters" }, { status: 400 });
  const target = process.env.LEAD_API_INTERNAL_URL;
  if (target) {
    try {
      const response = await fetch(`${target.replace(/\/$/, "")}/api/v1/chat/respond`, { method: "POST", signal: AbortSignal.timeout(8_000), headers: { "content-type": "application/json" }, body: JSON.stringify(input) });
      if (response.ok) return NextResponse.json(await response.json());
    } catch { /* grounded local guide is the safe fallback */ }
  }
  return NextResponse.json({ message: localGuide(input.message.trim()), provider: "next-local-guide", grounded: true, disclaimer: "Product guidance only; no private records were exposed to the assistant." });
}
