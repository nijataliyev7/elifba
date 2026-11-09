# City maps

`content/assets/cities/{file}.svg` (the `cities` pack's pictures) are generated here: one outline of Azerbaijan, identical in every file, with a pin on the city (docs/architecture.md §8). Only the generator and its coordinate table are committed; the Natural Earth download is not.

## Regenerate

1. Download Natural Earth 1:10m Cultural Vectors, Admin 0 – Countries, version 5.1.2 (public domain) as GeoJSON, outside the repository:

    ```sh
    curl -L -o /tmp/ne_10m_admin_0_countries.geojson \
        https://raw.githubusercontent.com/nvkelso/natural-earth-vector/v5.1.2/geojson/ne_10m_admin_0_countries.geojson
    ```

2. Run the generator (plain PHP, no dependencies):

    ```sh
    php scripts/city-maps/generate.php /tmp/ne_10m_admin_0_countries.geojson
    ```

    It writes the 29 files into `content/assets/cities/` (a second argument writes elsewhere) and stops if a point in `cities.csv` falls outside Azerbaijan's border.

3. Bump the `cities` pack's `version` and run `php artisan content:validate`.

## Method

- **Outline.** The `ADM0_A3 = AZE` feature of Natural Earth's admin-0 countries: one polygon for the mainland with Karabakh inside it and no line between them, and one for Nakhchivan, both inside the internationally recognised border. Parts smaller than 2 grid units² (about 130 km²; one grid unit is about 8 km) vanish at 72×72 and are left out: the small exclaves, the Artsvashen enclave and the Caspian islands. Equirectangular projection with longitudes shortened by cos 40°, fitted with every pin into OpenMoji's 4–68 safe area, then simplified (Douglas–Peucker, 0.25 grid units).
- **Points.** `cities.csv`: Natural Earth 1:10m populated places 5.1.2 where the city is there (its `ne_id` is given), otherwise the coordinates shown on the city's English Wikipedia article (retrieved 2026-11-09), one source per row. Natural Earth's own `Agdam` point is a village in Tovuz district, not the city, so Ağdam uses Wikipedia. Nothing comes from OpenStreetMap (ODbL is not an allowed license, §8). Every point must lie inside the detailed border; a coastal town may lie up to 0.25 grid units (about 2 km) off Natural Earth's generalised coast (Astara does).
- **Look.** OpenMoji's style (§8): land `#B1CC33`, a red `#EA5A47` pin with a white dot whose tip is on the city, black outlines of width 2.

Neighbouring towns get nearly the same picture at this scale (Gəncə and Göygöl, Quba and Qusar, Bakı and Sumqayıt); the word on the card tells them apart.
