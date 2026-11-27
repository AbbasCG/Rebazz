import { useGSAP } from '@gsap/react';
import gsap from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';
import { AnimatePresence, motion } from 'motion/react';
import { useEffect, useMemo, useRef, useState, type FormEvent } from 'react';
import ShopChrome, { useShopUI } from '../components/ShopChrome';
import { Icon, MButton, ProductVisual, Stars, useToast } from '../components/ui';
import { api } from '../lib/api';
import { escapeHtml, useI18n } from '../lib/i18n';
import { useShop } from '../lib/shop';
import { productPath } from '../lib/slug';

gsap.registerPlugin(ScrollTrigger, useGSAP);

export default function Home() {
  return (
    <ShopChrome>
      <HomeContent />
    </ShopChrome>
  );
}

function HomeContent() {
  const { t, list, pName, category, country, locale } = useI18n();
  const { catalog, money, addToCart } = useShop();
  const { openProduct, query } = useShopUI();
  const toast = useToast();
  const [cat, setCat] = useState('Alle');
  const [email, setEmail] = useState('');
  const scope = useRef<HTMLDivElement>(null);

  // Terug van de link in de bevestigingsmail van de nieuwsbrief (?newsletter=confirmed).
  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    if (params.get('newsletter') === 'confirmed') {
      toast(t('nl_confirmed'));
      params.delete('newsletter');
      const qs = params.toString();
      window.history.replaceState(null, '', window.location.pathname + (qs ? '?' + qs : ''));
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const products = catalog.products.filter(p => p.status === 'active');
  const cats = ['Alle', ...new Set(products.map(p => p.category))];
  const list2 = useMemo(() => {
    const q = query.trim().toLowerCase();
    return products.filter(p => (cat === 'Alle' || p.category === cat) && (!q || (pName(p) + ' ' + p.name + ' ' + category(p.category)).toLowerCase().includes(q)));
  }, [products, cat, query, pName, category]);
  const hero = products.slice(0, 2);
  const titleWords = t('hero_title').split(/(<em>.*?<\/em>|\s+)/).filter(s => s && !/^\s+$/.test(s));

  /* ---------- GSAP: introductie, 3D-kanteling en scroll-onthulling ---------- */
  useGSAP(() => {
    const mm = gsap.matchMedia();
    mm.add({ motionOk: '(prefers-reduced-motion: no-preference)', fine: '(pointer: fine)' }, ctx => {
      const { motionOk, fine } = ctx.conditions as { motionOk: boolean; fine: boolean };
      if (!motionOk) return;

      const tl = gsap.timeline({ defaults: { ease: 'power3.out' } });
      tl.from('.hero .eyebrow', { y: 12, opacity: 0, duration: 0.5 })
        .from('.hero h1 .w', { yPercent: 60, opacity: 0, rotateX: -50, transformOrigin: '50% 100%', stagger: 0.05, duration: 0.7 }, '-=0.25')
        .from('.hero .lead, .hero-cta, .hero-stats', { y: 16, opacity: 0, stagger: 0.08, duration: 0.55 }, '-=0.45')
        .from('.hero-card', { y: 50, opacity: 0, rotateY: (i: number) => (i ? -18 : 18), rotateX: 10, stagger: 0.12, duration: 0.9 }, '-=0.6')
        .from('.hero-badge', { scale: 0.8, opacity: 0, duration: 0.4 }, '-=0.3');

      gsap.to('.hero-glow', { xPercent: 12, yPercent: 10, scale: 1.08, duration: 6, ease: 'sine.inOut', yoyo: true, repeat: -1 });

      ScrollTrigger.batch('.reveal', {
        start: 'top 88%',
        once: true,
        onEnter: els => gsap.fromTo(els, { y: 28, opacity: 0 }, { y: 0, opacity: 1, stagger: 0.08, duration: 0.6, ease: 'power2.out', overwrite: true })
      });

      if (fine) {
        const visual = document.querySelector<HTMLElement>('.hero-visual');
        const cards = gsap.utils.toArray<HTMLElement>('.hero-card');
        if (visual && cards.length) {
          const setters = cards.map(c => ({ rx: gsap.quickTo(c, 'rotationX', { duration: 0.5, ease: 'power3' }), ry: gsap.quickTo(c, 'rotationY', { duration: 0.5, ease: 'power3' }) }));
          const onMove = (e: PointerEvent) => {
            const r = visual.getBoundingClientRect();
            const x = (e.clientX - r.left) / r.width - 0.5;
            const y = (e.clientY - r.top) / r.height - 0.5;
            setters.forEach((s, i) => { s.ry(x * (i ? 16 : 22)); s.rx(-y * (i ? 12 : 16)); });
          };
          const onLeave = () => setters.forEach(s => { s.rx(0); s.ry(0); });
          visual.addEventListener('pointermove', onMove);
          visual.addEventListener('pointerleave', onLeave);
          return () => { visual.removeEventListener('pointermove', onMove); visual.removeEventListener('pointerleave', onLeave); };
        }
      }
    });
    return () => mm.revert();
  }, { scope, dependencies: [hero.length] });

  const subscribe = async (e: FormEvent) => {
    e.preventDefault();
    try { await api.newsletter(email, locale); setEmail(''); toast(t('nl_check')); }
    catch { toast(t('co_error'), 'error'); }
  };

  return (
    <div ref={scope}>
      <section className="hero">
        <div className="container">
          <div>
            <span className="eyebrow">{t('hero_eyebrow')}</span>
            <h1 style={{ perspective: 600 }}>
              {titleWords.map((w, i) => <span key={i}><span className="w" dangerouslySetInnerHTML={{ __html: w }} />{' '}</span>)}
            </h1>
            <p className="lead">{t('hero_lead')}</p>
            <div className="hero-cta">
              <a href="#collectie" className="btn btn-primary btn-lg">{t('cta_shop')}</a>
              <a href="#werkwijze" className="btn btn-outline btn-lg">{t('cta_how')}</a>
            </div>
            <div className="hero-stats">
              <div><strong>{t('stat_countries_val')}</strong><span>{t('stat_countries')}</span></div>
              <div><strong>{t('st_ret_val')}</strong><span>{t('st_ret')}</span></div>
              <div><strong>8</strong><span>{t('st_lang')}</span></div>
            </div>
          </div>
          <div>
            <div className="hero-glow" aria-hidden="true" />
            <div className="hero-visual">
              {hero.map(p => (
                <button key={p.id} className="hero-card" style={{ cursor: 'pointer', border: 0, textAlign: 'start', font: 'inherit', color: 'inherit' }} onClick={() => openProduct(p)}>
                  <ProductVisual p={p} />
                  <div className="meta"><b>{pName(p)}</b><span>{money(p.price)}</span></div>
                </button>
              ))}
              <div className="hero-badge"><div className="ic"><Icon name="truck" size={18} /></div><div><b>{t('hero_badge_t')}</b><br /><span className="muted">{t('hero_badge_d')}</span></div></div>
            </div>
          </div>
        </div>
      </section>

      <div className="usp">
        <div className="container">
          {([['globe', 'usp1'], ['lock', 'usp2'], ['refresh', 'usp3'], ['message', 'usp4']] as const).map(([ic, k]) => (
            <div className="usp-item reveal" key={k}><div className="ic"><Icon name={ic} size={20} /></div><div><strong>{t(k + '_t')}</strong><span>{t(k + '_d')}</span></div></div>
          ))}
        </div>
      </div>

      <section className="section" id="collectie">
        <div className="container">
          <div className="section-head">
            <div><h2>{t('coll_title')}</h2><p>{t('coll_lead')}</p></div>
            <div className="chips" role="tablist">
              {cats.map(c => (
                <motion.button key={c} className={'chip' + (c === cat ? ' active' : '')} role="tab" aria-selected={c === cat} onClick={() => setCat(c)} whileTap={{ scale: 0.95 }}>
                  {c === 'Alle' ? t('cat_all') : category(c)}
                </motion.button>
              ))}
            </div>
          </div>
          <motion.div className="grid" layout>
            <AnimatePresence mode="popLayout">
              {list2.length === 0 && <motion.div key="empty" className="empty-state" initial={{ opacity: 0 }} animate={{ opacity: 1 }}>{t('no_results')}</motion.div>}
              {list2.map(p => {
                const discount = p.compare && p.compare > p.price ? Math.round((1 - p.price / p.compare) * 100) : 0;
                return (
                  <motion.article key={p.id} className="product-card" layout initial={{ opacity: 0, scale: 0.96 }} animate={{ opacity: 1, scale: 1 }} exit={{ opacity: 0, scale: 0.96 }}
                    whileHover={{ y: -4 }} transition={{ type: 'spring', stiffness: 320, damping: 28 }}>
                    <div className="media" onClick={() => openProduct(p)} role="button" tabIndex={0} onKeyDown={e => { if (e.key === 'Enter') openProduct(p); }} aria-label={pName(p)}>
                      {discount > 0 && <span className="tag">−{discount}%</span>}
                      {/EU|VS/.test(p.warehouse) && p.inStock !== false && <span className="tag fast">{t('fast')}</span>}
                      {p.inStock === false && <span className="tag" style={{ background: 'var(--danger, #c0392b)', color: '#fff' }}>{t('sold_out')}</span>}
                      {p.stockLeft ? <span className="tag" style={{ top: 'auto', bottom: 10 }}>{t('low_stock', { n: p.stockLeft })}</span> : null}
                      <ProductVisual p={p} />
                    </div>
                    <div className="body">
                      <span className="cat">{category(p.category)}</span>
                      <h3><a href={productPath(p)} onClick={e => { if (!e.metaKey && !e.ctrlKey && !e.shiftKey && e.button === 0) { e.preventDefault(); openProduct(p); } }} style={{ color: 'inherit', textDecoration: 'none' }}>{pName(p)}</a></h3>
                      {p.reviews > 0 && <div className="rating"><Stars rating={p.rating} /><span>{p.rating.toLocaleString(locale)} ({p.reviews})</span></div>}
                      <div className="price-row">
                        <span className="price">{p.variants?.length && Math.min(...p.variants.map(v => v.price)) !== Math.max(...p.variants.map(v => v.price)) ? t('from_price', { price: money(Math.min(...p.variants.map(v => v.price))) }) : money(p.variants?.length ? p.variants[0].price : p.price)}{!p.variants?.length && p.compare && p.compare > p.price ? <small className="strike">{money(p.compare)}</small> : null}</span>
                        <MButton className="btn btn-primary btn-sm" disabled={p.inStock === false} onClick={() => { if (p.variants?.length) { openProduct(p); return; } addToCart(p.id); toast(t('added', { name: pName(p) })); }}><Icon name={p.variants?.length ? 'arrow' : 'plus'} size={15} />{p.variants?.length ? t('choose_options') : t('add_to_cart')}</MButton>
                      </div>
                    </div>
                  </motion.article>
                );
              })}
            </AnimatePresence>
          </motion.div>
        </div>
      </section>

      <section className="section" id="werkwijze" style={{ paddingTop: 0 }}>
        <div className="container">
          <div className="section-head"><div><h2>{t('how_title')}</h2><p>{t('how_lead')}</p></div></div>
          <div className="steps">
            {['s1', 's2', 's3'].map((s, i) => <div className="step reveal" key={s}><div className="num">0{i + 1}</div><h3>{t(s + '_t')}</h3><p>{t(s + '_d')}</p></div>)}
          </div>
        </div>
      </section>

      {catalog.reviews.length > 0 && (
        <section className="section reviews" id="reviews">
          <div className="container">
            <div className="section-head"><div><h2>{t('rev_title')}</h2><p>{t('rev_lead')}</p></div></div>
            <div className="review-grid">
              {catalog.reviews.map((r, i) => (
                <article className="review reveal" key={i}>
                  <Stars rating={r.rating ?? 5} /><p>“{r.text}”</p>
                  <div className="who"><div className="avatar">{r.name[0]}</div><div>{r.name}<span>{r.city}{r.country ? ', ' + country(r.country) : ''} · {t('verified')}</span></div></div>
                </article>
              ))}
            </div>
          </div>
        </section>
      )}

      <section className="section" id="faq">
        <div className="container">
          <div className="section-head" style={{ justifyContent: 'center', textAlign: 'center' }}>
            <div><h2>{t('faq_title')}</h2><p style={{ marginInline: 'auto' }} dangerouslySetInnerHTML={{ __html: t('faq_lead', { email: '<a href="mailto:' + escapeHtml(catalog.brand.email) + '">' + escapeHtml(catalog.brand.email) + '</a>' }) }} /></div>
          </div>
          <div className="faq">
            {list<[string, string][]>('faq').map(([q, a]) => (
              <details key={q} className="reveal"><summary>{q}<Icon name="chevron" size={18} /></summary><p>{a}</p></details>
            ))}
          </div>
        </div>
      </section>

      <section className="container">
        <div className="newsletter reveal">
          <div><h2>{t('nl_title')}</h2><p>{t('nl_lead')}</p></div>
          <form onSubmit={subscribe}>
            <input className="input" id="nlEmail" type="email" required placeholder={t('nl_ph')} aria-label={t('nl_ph')} value={email} onChange={e => setEmail(e.target.value)} />
            <MButton className="btn btn-primary btn-lg" type="submit">{t('nl_btn')}</MButton>
            <span className="hint" dangerouslySetInnerHTML={{ __html: t('nl_hint').replace('juridisch.html', '/juridisch') }} />
          </form>
        </div>
      </section>
    </div>
  );
}
