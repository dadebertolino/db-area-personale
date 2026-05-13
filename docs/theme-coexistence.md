# Coesistenza con il tema Design Scuole Italia

Questo documento specifica come il plugin `db-area-personale` convive con il tema
[**design-scuole-wordpress-theme**](https://github.com/italia/design-scuole-wordpress-theme)
del modello AgID per i siti scolastici, di cui il sito istituzionale CBG è
un'adozione (via child theme).

> Versione di riferimento del tema: **2.17.3** (gennaio 2026).
> Licenza del tema: **AGPL-3.0**. Il plugin resta **GPL-2.0-or-later** (sono
> opere separate; l'AGPL del tema non si propaga al plugin).

## 1. Principio generale

Il sito istituzionale (`/`, `/scuola/`, `/servizi/`, `/circolari/`, `/eventi/`, ecc.)
è **renderizzato dal tema** secondo il modello AgID. Il plugin **non altera**
alcuna route, nessun template, nessuna tassonomia, nessun CPT del tema.

Il plugin opera in tre modalità di rendering distinte:

| Contesto | Header/footer del tema | Asset del tema | Asset del plugin |
|---|---|---|---|
| **Sito istituzionale** (tutte le pagine del tema) | Sì | Sì | No |
| **Area personale** (`/area-personale/*`) | **No** (shell custom) | **No** | Sì |
| **Route pubbliche aggiunte dal plugin** (es. `/bacheca-sindacale-pubblica/`) | Sì | Sì | Solo CSS minimo del plugin |

L'isolamento è bidirezionale: il tema non sa che esiste l'area personale, e
l'area personale non carica alcun asset del tema.

## 2. Override della modale di accesso

Il tema espone una **modale di accesso** configurabile dal Customizer
(sezione *accesso ai servizi*) che presenta:

- link verso servizi esterni (registro elettronico, ecc.);
- un modulo di login a WordPress (`wp-login.php`).

Il plugin **sostituisce il modulo di login WordPress** nella modale con due
pulsanti:

1. **Accedi con Google Workspace** → avvia il flusso OAuth2/OIDC del plugin
   (vedi §2.2 documento requisiti).
2. **Accedi con utenza locale** → form locale di fallback per supplenti e
   account di servizio (vedi §2.2).

Il link ai **servizi esterni** (registro elettronico, ecc.) configurati nel
Customizer è **preservato**: la modale resta lo stesso punto di accesso unificato.

### Tecnica di override

Il plugin intercetta il rendering del template-part della modale del tema
tramite il filter standard `wp_login_form` (per il form HTML) e, dove non
sufficiente, tramite `template_include` su `wp-login.php` per redirigere a
`/area-personale/login/`. La modale è modificata via filter pubblici del tema
quando disponibili, altrimenti tramite hook generici (`render_block`,
`the_content`) con detection dell'azione del form.

Tutti i tentativi di accesso a `wp-login.php` per ruoli non amministrativi
vengono rediretti a `/area-personale/login/`. Solo il ruolo `administrator`
mantiene l'accesso diretto a `wp-login.php` per evitare lockout in caso di
malfunzionamento dell'SSO.

## 3. Routing e shell custom

Tutte le route `/area-personale/*` sono intercettate via `template_redirect` a
priorità alta (5) **prima** che il tema decida quale template caricare.
Il plugin chiama poi `template_include` con un proprio file shell che:

- non include `header.php`/`footer.php` del tema;
- carica solo gli asset del plugin (Alpine.js, Tailwind, sprite Lucide);
- chiude la response con `exit` per impedire fallback al tema.

Per le route **pubbliche** aggiunte dal plugin (es. `/bacheca-sindacale-pubblica/`)
si usa invece il pattern WordPress standard: il plugin registra una rewrite
rule, intercetta la query, popola `$wp_query` con un loop custom, e lascia che
il tema renderizzi attraverso `page.php` (o template più specifico se
disponibile). In questo modo la pagina pubblica eredita header, footer e stile
istituzionale.

## 4. CPT, tassonomie e content type del tema da rispettare

Il tema registra numerosi CPT e tassonomie. Il plugin **non duplica** né
sovrascrive nessuno di essi. In particolare:

| CPT / Tassonomia del tema | Uso da parte del plugin |
|---|---|
| `circolare` (CPT) | **Sorgente** per il widget *Circolari recenti* e per la sezione `/area-personale/circolari/`. Il plugin legge, non crea. La creazione resta tramite il flusso del tema/Segreteria Digitale. |
| `evento` (CPT) | **Sorgente** per widget *Calendario settimana* e *Eventi classe*. Sola lettura. |
| `documento` (CPT) | **Sorgente** per allegati richiamati da widget e dashboard. Sola lettura. |
| `articolo` (post nativo) | **Non interferito**. Le news del plugin sono CPT separato `cbg_news_interna`. |
| `classe` (tassonomia) | **Non interferita**. La nostra anagrafica `cbg_ap_classi` ha scopo strutturale (associazione studenti/docenti, query audience). Se opportuno, il plugin può sincronizzare i nomi tra i due (one-way, opzionale, configurabile). |
| `servizio`, `struttura`, `luogo`, `persona`, `indirizzo` | **Non interferiti**. |

I CPT introdotti dal plugin (`cbg_news_interna`, `cbg_bacheca_sindacale`,
`cbg_comunicazione_ds`) hanno tutti il **prefisso `cbg_`** e quindi non
collidono con quelli del tema (prefisso vuoto).

## 5. Gutenberg

Il tema **non supporta Gutenberg** e raccomanda l'installazione del plugin
*Disable Gutenberg*. Il plugin `db-area-personale`:

- Non richiede Gutenberg in alcuna delle sue funzioni.
- L'editor frontend per news/bacheca/comunicazioni DS è basato su TinyMCE
  ridotto (vedi §3.3 documento requisiti) — non Gutenberg embedded.
- L'eventuale presenza di *Disable Gutenberg* è **innocua** rispetto al plugin.
- I pannelli admin del plugin usano UI custom (form HTML standard) e CMB2 dove
  serve coerenza con il tema; non Gutenberg.

## 6. Stack frontend e isolamento JS/CSS

Il tema usa **Bootstrap Italia + Bootstrap 4 + jQuery**. Il plugin usa
**Alpine.js + Tailwind CSS** nella sola area personale. L'isolamento è
ottenuto come segue:

- Gli asset del plugin sono enqueueati condizionalmente: solo se la richiesta
  corrente è risolta dal router del plugin (verifica su query var
  `cbg_ap_route`).
- Il template shell dell'area personale **non chiama** `wp_head()` /
  `wp_footer()` nella forma piena del tema, ma una versione minima che
  esegue solo gli enqueue di plugin attivi e non emette gli script del tema.
  In pratica, `wp_dequeue_script` su tutti gli handle del tema prima di
  `wp_head` quando siamo nell'area personale.
- Tailwind è generato con `prefix: 'cbg-'` per ridurre il rischio di
  collisione con classi Bootstrap (`.btn`, `.card`, ecc.) se per qualche
  ragione i due CSS finissero sulla stessa pagina (es. errore di
  configurazione).
- Le route pubbliche del plugin che girano dentro il tema usano **solo CSS
  scoped** con classi prefissate `cbg-ap-public-*` per evitare ogni
  interferenza.

## 7. CMB2 e UI admin

Il tema usa CMB2 per i metabox dei propri CPT. Il plugin **riusa CMB2** se
disponibile (cioè quasi sempre nei siti che usano il tema) per i metabox dei
propri CPT, evitando di caricare una seconda libreria di metabox. CMB2 è
*peer dependency* opzionale:

- Se disponibile → CMB2 è la UI per i metabox del plugin.
- Se assente → fallback a metabox WordPress nativi.

Le impostazioni del plugin (`Impostazioni → Area Personale`) usano comunque
form custom in stile **DB Admin UI** (design system dell'autore), non CMB2.

## 8. Override e child theme

Il tema raccomanda l'installazione come child theme. Su CBG il child theme
esistente potrebbe richiedere piccoli override (es. del template della modale
di accesso, se non risulta intercettabile via filter pubblici).

Il plugin **non installa né modifica** file nel child theme. Se servono
override, sono documentati in `docs/theme-coexistence-snippets.md` (da
aggiungere quando arriveremo al router) come **istruzioni per il manutentore**
del child theme.

## 9. Aggiornamenti del tema

Il tema `design-scuole-wordpress-theme` è in sviluppo attivo (43 release a
oggi). Il plugin si protegge dagli aggiornamenti del tema con:

- Nessuna dipendenza da file interni del tema (richieste solo via API/hook
  pubblici).
- Detection difensiva via `wp_get_theme()->get('TextDomain') === 'design_scuole_italia'`
  in modo da degradare con grazia se il tema viene sostituito.
- Test di regressione dedicati alla coesistenza in `tests/integration/coexistence/`.

## 10. Vincolo di compatibilità

Il plugin dichiara compatibilità con tema scuole **2.16+**. Versioni
precedenti potrebbero funzionare ma non sono testate. Se viene rilevata una
versione del tema fuori range, viene mostrato un avviso admin non bloccante
(vedi `CBG_AP_Plugin::check_theme_compatibility()`).

## 11. Diagramma di stack

```
┌──────────────────────────────────────────────────────────────────────┐
│ WordPress core 6.x + PHP 8.1+                                        │
├──────────────────────────────────────────────────────────────────────┤
│ Tema Design Scuole Italia (parent)                                   │
│   └── Child theme CBG (override grafici, modale accesso)             │
├──────────────────────────────────────────────────────────────────────┤
│ Plugin custom CBG                                                    │
│   ├── Segreteria Digitale                                            │
│   ├── Moduli Scuola                                                  │
│   ├── GESTIONESCUOLA                                                 │
│   └── ★ db-area-personale (questo plugin)                            │
└──────────────────────────────────────────────────────────────────────┘

Request lifecycle:

  HTTP request
       │
       ├── /area-personale/*  ──→  template_redirect (prio 5)
       │                            └── shell custom plugin
       │                                ├── solo asset plugin
       │                                └── no header/footer tema
       │
       ├── /bacheca-sindacale-pubblica/  ──→ rewrite plugin
       │                                     └── template_include tema (page.php)
       │                                         └── header/footer tema + CSS scoped plugin
       │
       ├── wp-login.php  ──→  redirect a /area-personale/login/
       │                       (eccetto administrator)
       │
       └── tutto il resto  ──→  template_include tema (rendering standard AgID)
```

## 12. Riferimenti

- Documento di requisiti `db-area-personale`, **v1.2**, capitolo §1.3 e §5.
- Repository tema: <https://github.com/italia/design-scuole-wordpress-theme>
- Modello AgID scuole: <https://designers.italia.it/modelli/scuole/>
