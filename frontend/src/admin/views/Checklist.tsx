import { motion } from 'motion/react';
import { useEffect, useState } from 'react';
import { useToast } from '../../components/ui';
import { api } from '../../lib/api';
import { CHECKLIST } from '../../lib/i18n';

export default function Checklist() {
  const toast = useToast();
  const [state, setState] = useState<Record<string, boolean>>({});
  useEffect(() => { api.admin.setting<Record<string, boolean>>('checklist').then(r => setState(r.value ?? {})).catch(() => {}); }, []);
  const total = CHECKLIST.reduce((s, g) => s + g.items.length, 0);
  const done = Object.values(state).filter(Boolean).length;
  const toggle = async (k: string, v: boolean) => {
    const next = { ...state, [k]: v };
    setState(next);
    try { await api.admin.saveSetting('checklist', next); } catch { toast('Opslaan mislukt', 'error'); }
  };
  return (
    <>
      <div className="card" style={{ marginBottom: 20 }}><div className="card-body">
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline', gap: 12, flexWrap: 'wrap' }}>
          <div><h3 style={{ fontSize: 18 }}>Gereed voor lancering: {Math.round((done / total) * 100)}%</h3><p className="muted" style={{ fontSize: 13.5 }}>{done} van {total} onderdelen afgerond. Uw voortgang wordt in de database bewaard.</p></div>
          <span className={'badge ' + (done === total ? 'success' : 'warn')}>{done === total ? 'Klaar om live te gaan' : 'Nog niet gereed'}</span>
        </div>
        <div className="progress-big"><motion.i animate={{ width: (done / total) * 100 + '%' }} transition={{ type: 'spring', stiffness: 120, damping: 20 }} /></div>
      </div></div>
      <div className="grid-2">
        {CHECKLIST.map((g, gi) => (
          <div className="card" key={g.group}><div className="card-head"><h3>{g.group}</h3></div><div className="card-body" style={{ paddingTop: 6 }}>
            {g.items.map((it, ii) => {
              const k = gi + '-' + ii;
              return <label key={k} className={'check-item' + (state[k] ? ' done' : '')}><input type="checkbox" checked={!!state[k]} onChange={e => toggle(k, e.target.checked)} /><span>{it}</span></label>;
            })}
          </div></div>
        ))}
      </div>
    </>
  );
}
