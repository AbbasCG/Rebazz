import { useEffect, useState } from 'react';
import { api } from '../lib/api';
import type { AiStatus } from '../lib/types';

/** Status van de AI-koppeling (sleutel ingesteld? gebruik vandaag). */
export function useAiStatus(refreshKey: unknown = 0): AiStatus | null {
  const [s, setS] = useState<AiStatus | null>(null);
  useEffect(() => { api.admin.ai.status().then(setS).catch(() => setS(null)); }, [refreshKey]);
  return s;
}

/** Korte uitleg onder AI-knoppen: wat er gebeurt en dat de gebruiker het resultaat controleert. */
export function AiNote({ status, extra }: { status: AiStatus | null; extra?: string }) {
  if (status && !status.enabled) {
    return <p className="hint" role="status" style={{ color: 'var(--warning, #b7791f)' }}>AI is nog niet ingesteld: zet <code>ANTHROPIC_API_KEY</code> in het .env-bestand van de server (zie DEPLOY.md).</p>;
  }
  return (
    <p className="hint">
      Gemaakt door Claude (Anthropic) als <b>voorstel</b>: controleer de feiten voordat u iets gebruikt.{extra ? ' ' + extra : ''}
      {status ? ` Vandaag gebruikt: ${status.today.mine}/${status.today.myLimit}.` : ''}
    </p>
  );
}
