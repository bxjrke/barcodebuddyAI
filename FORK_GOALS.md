# BarcodeBuddyAI – Ziele für den persönlichen Fork

## Zielbild
Eigene, stabile BarcodeBuddy-Version nur für Basti mit erweitertem Autopilot-Flow für Grocy.

## Produktziele

1. **Modernisiertes UI (persönlicher Fokus)**
   - klarere Seitenstruktur (weniger Klicks im täglichen Flow)
   - modernere Komponenten (Buttons, Form-Layout, States, Feedback)
   - mobile-first Optimierungen für schnelles Scannen

2. **Provider aufräumen**
   - unnötige Lookup-Provider deaktivieren/entfernen
   - Fokus auf die tatsächlich genutzten Provider (inkl. OpenAI)
   - reduzierte Komplexität in Settings und Lookup-Pipeline

3. **Grocy API erweitern**
   - zusätzliche Endpunkte/Felder unterstützen
   - robustere Fehlerbehandlung + bessere Rückmeldungen im UI
   - klare Trennung: Produkt finden vs. Produkt anlegen vs. Bestand buchen

4. **Vollautomatische Produktanlage verbessern**
   - Lookup um zusätzliche Datenfelder erweitern (z. B. Kategorie, Einheit, Einkaufspreis, Mindestbestand, Lagerort, Qu-Einheiten, Notizen/Tags)
   - Mapping-Schicht: Lookup-Feld -> Grocy-Feld
   - Validierungs- und Fallback-Logik bei unvollständigen Daten

5. **Betriebssicheres eigenes Docker-Image**
   - reproduzierbarer Build direkt aus diesem Fork
   - Versionierung/Tagging für stabile Rollbacks
   - Deployment über bestehende Reverse-Proxy-Architektur

## Phasenplan (kurz)

### Phase 1 – Stabiler Build (jetzt)
- [x] Dockerfile im Fork
- [x] docker-compose Beispiel ohne Host-Port
- [ ] lokaler Build + Smoke-Test (UI erreichbar, Login, Scan-Flow)

### Phase 2 – UI-Basis
- [ ] bestehende UI-Flows inventarisieren
- [ ] "Daily Use" Screen priorisieren
- [ ] Design-Token/kleines Style-System einziehen

### Phase 3 – Lookup & Grocy Autopilot
- [ ] gewünschte Zielfelder in Grocy final festlegen
- [ ] Datenmodell/Mapping implementieren
- [ ] Auto-Create Pipeline mit Guardrails + Dry-Run-Modus

### Phase 4 – Provider-Cleanup
- [ ] nicht genutzte Provider per Feature-Flag deaktivieren
- [ ] optional harte Entfernung im Fork

## Definition of Done (v1 im Fork)
- Ein Barcode kann bei unbekanntem Produkt mit minimalen Rückfragen in Grocy automatisch angelegt werden.
- Pflichtfelder und sinnvolle Defaults sind abgedeckt.
- Der Prozess ist im UI nachvollziehbar (warum wurde was gesetzt).
- Docker-Image ist reproduzierbar und läuft stabil im bestehenden Stack.
