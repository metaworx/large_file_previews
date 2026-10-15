# German glossary

The terms both German translations use, `de` and `de_DE` alike.
A new text takes them from here; a term that changes,
changes here and in both `.po` files at once,
and in `appinfo/info.xml` where its summary or description has it.

## Registers

- `de` is Nextcloud's informal German: *du*, *dein*.
- `de_DE` is its formal German: *Sie*, *Ihr*.
  It differs from `de` only where a text addresses the reader.
- `appinfo/info.xml`'s summary and description are `lang="de"`,
  the one German the app store knows, and take `de`'s *du*.
- A label or button names its action in the infinitive, the same in both:
  „Befehl kopieren“, „Programme erneut suchen“.

## Terms

| English | German | Note |
|---|---|---|
| preview | Vorschau, Vorschauen | |
| preview provider, provider | Vorschau-Anbieter, Anbieter | Nextcloud's other providers: „die anderen Vorschau-Anbieter von Nextcloud“ |
| render (verb) | rendern | |
| render (noun), a render | Rendervorgang | one run of a renderer for one preview; „pro Rendervorgang“ |
| renderer | Renderer | |
| process, processing | verarbeiten, Verarbeitung | what the app takes: „Was verarbeitet wird“ |
| handle (a type) | behandeln | „ein Typ, den diese App nicht behandelt“ |
| MIME type | MIME-Typ, MIME-Typen | |
| extension | Dateiendung, Dateiendungen | not *Erweiterung*, which is a PHP extension |
| PHP extension | PHP-Erweiterung | |
| default, defaults | Standard, Standardwerte | |
| inherited | geerbt | a size field's placeholder: „50 (geerbt)“ |
| override | Vorrang haben | „hat Feld für Feld Vorrang vor“; not *übersteuern*, which is Swiss usage |
| enabled / disabled / excluded | aktiviert / deaktiviert / ausgeschlossen | a rule's processing, and how the help text quotes it |
| limit, limits | Grenze, Grenzen | the section; „keine Grenze“ for no limit |
| cap | Obergrenze | the address-space, file-size and libvips caps |
| address space | Adressraum | |
| grace period | Nachfrist | between SIGTERM and SIGKILL |
| out-of-memory score | Out-of-Memory-Score | the kernel's term, kept |
| timeout | Timeout | |
| thread | Thread, Threads | |
| system config | Systemkonfiguration | `config.php`; „nur in der Systemkonfiguration“ |
| app config | App-Einstellungen | Nextcloud's `appconfig`, as in „den App-Einstellungen preview jpeg_quality“ |
| memory cache | Memory-Cache | |
| scratch directory | Arbeitsverzeichnis | |
| strip (of a TIFF) | Streifen | |
| pyramid, level | Pyramide, Ebene | |
| scanned page | gescannte Seite | |
| coverage | Abdeckung | |
| decoder | Decoder | |
| untrusted | nicht vertrauenswürdig | libvips' term |
| blocked / allowed | gesperrt / erlaubt | untrusted decoders |
| find (a program) again | erneut suchen | |
| available / missing / not found | vorhanden / fehlt / nicht gefunden | |
| failed (a render, a file) | fehlgeschlagen | „Fehlgeschlagene Dateien“ |
| setting | Einstellung | |
| this server | dieser Server | not *diese Instanz* |
| disk | Festplatte | „auf der Festplatte“, against tmpfs and ramfs |

## Style

- The app's name, *Large File Previews*, stays English.
- German spelling, not Swiss: *ß* where it belongs („Größe“, „Weiß“).
- A name in running text takes German quotes and the exact German of that
  element's own label: „Erweitert“, „aktiviert“.
  A value shown or typed as it is keeps straight quotes or code,
  and stays English:
  `webp`, `true`, `preview_format`, `occ lfp:check --refresh`.
- A failure names what failed:
  „Die Einstellung konnte nicht gespeichert werden.“
- A decimal takes a comma: „1,7 GB“.
- `{placeholders}`, `%s`, `%1$s` and `%2$d` stay as they are.
