"use client";

import Image from "next/image";
import { useRouter } from "next/navigation";
import { useState, type FormEvent } from "react";
import { authApi } from "../../lib/leadDiscovery";
import { clearSessionTokens, saveSessionTokens } from "../../lib/session";

export function LoginForm({ admin = false }: { admin?: boolean }) {
  const [email, setEmail] = useState(""); const [password, setPassword] = useState(""); const [error, setError] = useState(""); const [busy, setBusy] = useState(false); const router = useRouter();
  const submit = async (event: FormEvent) => {
    event.preventDefault(); setBusy(true); setError("");
    try {
      const session = await authApi.login(email, password);
      if (admin && !session.permissions.includes("lead.admin")) { await authApi.logout(session.refreshToken).catch(() => undefined); clearSessionTokens(); throw new Error("This account does not have administrator access."); }
      saveSessionTokens(session.token, session.refreshToken); router.push(admin ? "/admin" : "/app/leads");
    } catch (exception) { setError(exception instanceof Error ? exception.message : "Sign in failed"); }
    finally { setBusy(false); }
  };
  return <main className="relative mx-auto flex min-h-[calc(100vh-73px)] max-w-6xl items-center justify-center px-4 py-12 sm:px-6"><div className="grid w-full max-w-4xl overflow-hidden rounded-3xl border border-slate-800 bg-slate-900 shadow-2xl md:grid-cols-[.9fr_1.1fr]"><div className="relative hidden min-h-[520px] md:block"><Image src={admin ? "/images/ai-agent-avatar.png" : "/images/customer-review.png"} alt={admin ? "Scout AI assistant" : "Scout customer workspace"} fill className="object-cover" /><div className="absolute inset-0 bg-gradient-to-t from-slate-950 via-slate-950/20 to-transparent" /><div className="absolute bottom-7 left-7 right-7"><p className="text-xs font-semibold uppercase tracking-[.22em] text-cyan-300">{admin ? "Control center" : "Private workspace"}</p><p className="mt-2 text-xl font-semibold text-white">{admin ? "Make every source and user accountable." : "Turn real business data into useful conversations."}</p></div></div><section className="p-7 sm:p-10"><div className="mb-8 flex items-center gap-3"><Image src="/images/scout-logo.png" alt="Scout" width={46} height={46} className="rounded-xl" /><div><p className="text-sm font-semibold uppercase tracking-[.2em] text-cyan-400">Scout</p><p className="text-xs text-slate-500">{admin ? "Administrator sign in" : "Lead intelligence"}</p></div></div><h1 className="text-3xl font-bold text-white">{admin ? "Admin sign in" : "Welcome back"}</h1><p className="mt-2 text-sm leading-6 text-slate-400">{admin ? "Manage organization users and review workspace activity." : "Sign in to your organization-scoped lead workspace."}</p><form onSubmit={submit} className="mt-8 space-y-4"><label className="block text-sm text-slate-300">Email<input type="email" required autoComplete="email" value={email} onChange={event => setEmail(event.target.value)} className="mt-1 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-3 text-white outline-none focus:border-cyan-400" /></label><label className="block text-sm text-slate-300">Password<input type="password" required autoComplete="current-password" value={password} onChange={event => setPassword(event.target.value)} className="mt-1 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-3 text-white outline-none focus:border-cyan-400" /></label>{error && <p role="alert" className="rounded-lg border border-red-900 bg-red-950/40 p-3 text-sm text-red-200">{error}</p>}<button disabled={busy} className="w-full rounded-xl bg-cyan-400 px-4 py-3 font-semibold text-slate-950 transition hover:bg-cyan-300 disabled:cursor-not-allowed disabled:opacity-50">{busy ? "Signing in…" : admin ? "Enter control center" : "Sign in to Scout"}</button></form><p className="mt-6 text-center text-sm text-slate-500">{admin ? <>Need a user account? <a className="text-cyan-300 hover:underline" href="/login">Use user sign in</a></> : <>Administrator? <a className="text-cyan-300 hover:underline" href="/admin/login">Open admin sign in</a></>}</p></section></div></main>;
}
