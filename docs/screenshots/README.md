# Screenshots

The root `README.md` references the images below. They are intentionally **not committed** —
capture them from a running instance (seed first: `php artisan migrate:fresh --seed`, then log in
as `demo@example.com` / `password`) and drop the PNGs/GIFs in this folder with these exact names.

## Capture checklist

| File | What to capture | Notes |
|------|-----------------|-------|
| `landing.png` | The public landing page (`/`) | Logged out; light or dark, your choice |
| `dashboard.png` | The signed-in dashboard | Shows stat cards + recent documents |
| `library.png` | The document library (`/documents`) | Grid of cards with cover thumbnails |
| `viewer.png` | The PDF.js viewer (open a document) | Page rail + rendered page |
| `editor.png` | The overlay editor (`…/edit`) | Mid-edit: a text box + highlight on the page |
| `organize.png` | The page manager (`…/organize`) | Drag-and-drop reorder in progress |
| `forms-signature.png` | Signature pad / form fill | Draw or type a signature |
| `word-export.png` | The "Export to Word" modal | Pending → download revealed |
| `ai-assistant.png` | The AI assistant side panel | A grounded answer with `(p. N)` citations |
| `editor.gif` *(optional)* | A short happy-path GIF | Upload → edit → bake → download |

## Tips

- Use a clean browser window (no extensions toolbar) at ~1440px wide for crisp captures.
- The committed sample documents (`Welcome to Lapis`, `Quarterly Report`, `Service Invoice`)
  are designed to demo every feature — the report is multi-page for page ops, the invoice is great
  for Word export.
- Keep file sizes reasonable (compress PNGs; keep GIFs < ~5 MB) so the README loads fast.
