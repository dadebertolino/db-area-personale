# Descrizione

<!-- Cosa cambia e perché. -->

## Tipo di modifica

- [ ] Bugfix (cambio non breaking che risolve un problema)
- [ ] Nuova feature (cambio non breaking che aggiunge funzionalità)
- [ ] Breaking change (correzione o feature che modifica comportamento esistente)
- [ ] Documentazione
- [ ] Refactor / pulizia codice

## Riferimenti

- Closes # <!-- issue collegata -->
- Documento di requisiti: §X.Y

## Checklist

- [ ] Il codice segue gli standard del progetto (`composer lint`)
- [ ] Ho aggiunto/aggiornato i test PHPUnit dove rilevante
- [ ] `composer test` è verde
- [ ] Ho aggiornato `CHANGELOG.md` nella sezione `[Unreleased]`
- [ ] Ho aggiornato la documentazione in `docs/` se ho cambiato API pubbliche
- [ ] Ho verificato la coesistenza col tema Design Scuole Italia per le route
      `/area-personale/*` e per quelle pubbliche del plugin
- [ ] Le query DB usano `$wpdb->prepare()`
- [ ] Gli endpoint REST hanno `permission_callback` con capability check
- [ ] Gli endpoint POST/PUT/DELETE verificano il nonce `X-WP-Nonce`

## Note per il revisore

<!-- Qualunque cosa utile a chi farà la review. -->
