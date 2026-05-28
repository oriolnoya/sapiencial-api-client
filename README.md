# Sapiencial API Client (Import/Sync-only)

## Purpose
This plugin imports Sapiencial API content into local Craft entries and syncs updates manually.

## What it does
- Browse remote Sapiencial books from CP.
- Import a book and descendants (chapters, resources, persons) into local entries.
- Sync an imported book on demand.
- Hard-delete descendants removed upstream, scoped to that imported book.

## Required setup
Create local sections (and one entry type in each):
- `sapiencialBooks`
- `sapiencialChapters`
- `sapiencialResources`
- `sapiencialPersons`

You can change these handles in plugin settings.

Optional relation fields used by sync wiring (if present):
- On books: `sapiencialChapters`, `sapiencialPersons`
- On chapters: `sapiencialResources`

## Settings
- Base URL
- Bearer token
- Default site handle
- Timeout
- Section handles for imported entities
- Dry-run by default
