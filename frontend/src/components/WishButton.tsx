import { useAuth } from '../lib/auth';
import { useI18n } from '../lib/i18n';
import { Icon } from './ui';

/** Hartje om een product op de verlanglijst te zetten (gast: in de browser; ingelogd: in het account). */
export default function WishButton({ id, className = '' }: { id: string; className?: string }) {
  const { t } = useI18n();
  const { wish, toggleWish } = useAuth();
  const on = wish.includes(id);
  const label = t(on ? 'acc_remove_wish' : 'acc_add_wish');
  return (
    <button type="button" className={'wish-btn' + (on ? ' on' : '') + (className ? ' ' + className : '')} aria-pressed={on} aria-label={label} title={label}
      onClick={e => { e.stopPropagation(); toggleWish(id); }}>
      <Icon name="heart" size={18} />
    </button>
  );
}
