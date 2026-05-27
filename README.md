# Sapiencial API Client (Craft 5 plugin)

## Qué aporta
- 3 custom fields: `Sapiencial Book`, `Sapiencial Chapter`, `Sapiencial Resource`.
- Selector remoto searchable en CP.
- API Twig para traer contenido remoto con cache `stale-while-revalidate`.
- Persistencia en tabla propia + espejo opcional en fields del entry.

## Instalación en este monorepo
1. Ejecuta `composer update sapiencial/craft-sapiencial-api-client`.
2. Instala el plugin en Craft (`php craft plugin/install sapiencial-api-client`).
3. Configura settings del plugin (base URL, token, site por defecto, timeout).

## Uso en Twig
```twig
{% set data = craft.sapiencial.fetch(entry, 'mySapiencialBookField', {
  mirrorJsonFieldHandle: 'sapiencialPayloadJson',
  mirrorUpdatedAtFieldHandle: 'sapiencialPayloadUpdatedAt'
}) %}

{% if data %}
  <h2>{{ data.title }}</h2>
{% endif %}
```

También puedes usar la función:
```twig
{% set data = sapiencial_fetch(entry, 'mySapiencialBookField') %}
```

## Notas
- Primera llamada: fetch síncrono + guardado.
- Llamadas siguientes: devuelve caché inmediata y encola refresh en background.
