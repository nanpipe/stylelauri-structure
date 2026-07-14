# StyleLauri — Reorganización de operaciones y pedidos

> Brief de proyecto para gestionar la reestructuración del manejo de mensajes, pedidos y producción.
> Stack: WooCommerce sobre **HPOS**. Sin ACF — los campos de producto se manejan como **meta plano**.

---

## 1. La operación hoy

StyleLauri vende de forma 100% orgánica (TikTok, Instagram, masivo). Cuando algo se vende, los chats se estallan: la gente pregunta y pide por TikTok, IG y WhatsApp al mismo tiempo, y el pedido termina viviendo en la conversación en vez de en un sistema.

El catálogo mezcla dos naturalezas distintas:

- **Stock inmediato** — ramen, comida, llaveros. Se despacha ya.
- **Preventa por lote** — ropa K-pop (jerseys, hoodies, camisetas), Army Bomb, chaquetas. Se produce por encargo, con fecha de cierre de pedidos y fecha de despacho distintas por campaña (JK = tiempo X, CK = tiempo Y, producto nuevo = tiempo Z).

En la vitrina ya se muestra el lote ("Pedidos hasta Jul 15 · Despacho Ago 10"), pero esa lógica **no existe en la operación de pedidos**. Ahí está la raíz del caos.

## 2. El problema raíz

Se está usando **un solo campo ("Estado del pedido") para codificar cinco dimensiones distintas a la vez**: etapa del pedido, método de entrega, tipo de producción, estado de pago y lote. Como WooCommerce solo permite un estado por pedido, esas dimensiones colisionan y se pierde información. De ahí que "generamos listados pero no sabemos qué pasó con X pedido".

Los intentos previos fallaron por esto mismo, no por mala ejecución:

- "Merch pendiente / lista" colapsó campañas distintas en un solo bucket → verguero.
- "Un estado por preventa" (chaqueta, army bomb, etc.) se vuelve insostenible porque cada campaña nueva exige un estado nuevo.

Restricción real confirmada: **no hay herramienta hoy para añadir ni filtrar por atributos de pedido**, por eso todo se ha resuelto históricamente metiéndolo en estados — es lo único filtrable de fábrica. Esta restricción condiciona todo el diseño (ver sección 4).

## 3. El modelo objetivo

**Separar los ejes.** El estado deja de mezclar todo y queda para una sola cosa: la etapa del pedido. Lo demás pasa a ser atributo (meta del pedido).

### Eje 1 — Ciclo de vida (el único "estado")

Conjunto **cerrado** (~7 etapas, nunca crece). El estado es perfecto para esto.

| Estado | ¿Notifica al cliente? |
|---|---|
| Pendiente de pago | Sí (link de pago) |
| Abono parcial (pagó 50%, falta saldo) | Sí (confirmación + recordatorio de saldo) |
| Pagado / Procesando | Sí (confirmación) |
| En producción (solo preventa) | No — interno |
| Listo para despacho / retiro | No — interno |
| Enviado | Sí (con guía) |
| Completado | Opcional (cierre / gracias) |
| Cancelado | — |

### Ejes 2–5 — Atributos (no son estado)

- **Entrega** — domicilio / retiro / contraentrega (es el método de envío del checkout).
- **Lote / track** — army bomb, chaquetas, JK, CK, la próxima… Conjunto **abierto**: agregar una campaña nueva debe costar cero. Este es el atributo clave.
- **Pago** — total / abono 50%.
- **ETA del lote** — fecha de cierre + fecha de despacho.

### La idea que resuelve la tensión

El problema nunca fue "estado vs atributo". Fue meter una dimensión **abierta** (la campaña, que crece sin límite) dentro de un campo donde agregar un valor cuesta código (el estado). La campaña debe vivir donde agregar un valor sea gratis: **un solo atributo, el lote** (guardado como meta del pedido, ej. `_lote`). Un lote = grupo de pedidos que se produce y despacha junto y comparte ETA.

Con eso, en vez de estados que colisionan, se **filtra por dos ejes cruzados**: `estado = En producción` × `lote = JK-Agosto` devuelve exactamente ese grupo. El estado quedó chiquito y cerrado; el lote absorbe todo lo que crece.

**Producción por lotes:** no se procesa por fecha de pedido ni por listado suelto, se procesa por lote. El "listado" deja de ser un Excel que se desactualiza y pasa a ser una vista filtrada de la misma base de datos: siempre refleja la realidad.

## 4. Factibilidad técnica — RESUELTA (HPOS)

La creencia bloqueante ("no podemos filtrar por atributos de pedido, solo tenemos status") era **cierta** en el mundo viejo (CPT, meta serializado, queries lentas) y es la razón legítima por la que se llegó al diseño actual de status por preventa. **Con HPOS deja de ser cierta:** el metadata custom de pedidos se consulta con `meta_query` como ciudadano de primera clase, sobre columnas reales indexadas (`wp_wc_orders_meta`).

Montar la columna de lote + el dropdown de filtro es **una tarde**, con cuatro hooks nativos de HPOS:

- `manage_woocommerce_page_wc-orders_columns` — cabecera de la columna (sirve para HPOS y CPT).
- `manage_woocommerce_page_wc-orders_custom_column` — contenido de la celda (`$order` ya es `WC_Order`).
- `woocommerce_order_list_table_restrict_manage_orders` — el dropdown de filtro sobre el listado.
- `woocommerce_order_list_table_prepare_items_query_args` — inyecta el `meta_query`. Sin SQL: para un meta del pedido, `meta_query` lo resuelve. (Solo haría falta SQL si se filtrara por algo dentro de los line items, ej. por producto.)

**El exportador juega a favor, no en contra:** el lote es una columna más, `$order->get_meta('_lote')`. Una taxonomy "elegante" sería más trabajo de exportar; el meta es lo más barato precisamente porque el exportador es propio y ya está bajo control.

## 5. La pieza que crea el dato (por aquí se empieza)

Hoy la fecha de preventa es una etiqueta de texto suelta en el producto y no hay dónde el pedido la lea. Falta estructura en el producto — **sin ACF, con meta plano nativo de WordPress**:

- Dos campos por producto de preventa, guardados como post meta del producto (ej. `_preventa_cierre`, `_preventa_despacho`): **fecha de cierre** y **fecha de despacho**. Se agregan con un meta box simple en el editor de producto (`add_meta_box` + `save_post`), no requiere plugin adicional.
- Al hacer checkout, el pedido copia esos datos como **snapshot al pedido** (`_lote`, `_lote_fecha_despacho` como meta del pedido), **no como referencia viva** al producto — el producto se reusa en la próxima campaña con otras fechas; la promesa de *este* pedido no debe cambiar retroactivamente.

De ese snapshot sale: el `_lote` que alimenta el filtro (sección 4), y el mensaje dinámico "tu pedido quedó registrado, lo enviamos el {fecha}" en correo y WhatsApp. **Sin esta pieza no hay dato que filtrar ni fecha que comunicar** — por eso es el primer paso.

## 6. Gestión de mensajes (capa secundaria)

Cuatro capas, de más barata a más cara:

1. **Deflación en la fuente.** ~70–80% de los mensajes son las mismas ~8 preguntas (precio, disponibilidad, demora, cómo pago, envíos). Precio y fecha de despacho visibles en cada post + respuestas automáticas → mata volumen.
2. **Embudo a un solo canal.** TikTok/IG no cierran pedidos: comentario/DM → link a WhatsApp. El pedido se toma en un solo lugar.
3. **Triage con etiquetas** de WhatsApp Business: lead nuevo · esperando pago · pedido tomado · postventa · resuelto.
4. **Captura disciplinada.** Todo pedido confirmado entra a WooCommerce de inmediato. El chat acuerda; el sistema registra. Un pedido que vive solo en un chat es un pedido perdido.

Reutilizar el patrón de "Kate" (agente de WhatsApp de Niebla) como primer filtro automático para bajar de ~180 a los ~30 que sí necesitan a una persona.

## 7. Regla de notificaciones

Se notifica solo en los cambios que le importan al cliente; los movimientos internos son silenciosos. **Cada notificación de más genera una pregunta de vuelta.** Y comunicar la fecha de despacho *en la compra* (vía el snapshot de la sección 5) mata de raíz la mayoría de los "¿cuándo llega?".

## 8. Decisiones abiertas (cerrar antes de construir)

1. **Lote único o múltiple por pedido.** Un pedido con jersey (lote JK) + ramen (stock) + army bomb (lote AB) obliga a elegir: ¿un lote "que manda" (el más lento) con despacho parcial, se parte el pedido, o se prohíbe mezclar en el carrito? Es la misma decisión del carrito mixto. Recomendación: prohibir mezcla en carrito si se puede; despacho parcial si no.
2. **Columnas exactas del exportador** para que el export sea accionable (lote, fecha de despacho, estado de saldo).
3. ~~ACF vs meta plano~~ — **Resuelto: meta plano**, sin ACF. Meta box nativo en el editor de producto.

## 9. Roadmap (orden de ataque)

1. **Pieza de producto + snapshot** (sección 5) — crea el dato, con meta plano. Autocontenida, reduce los "¿cuándo llega?".
2. **Columna de lote + filtro** en el listado de pedidos (sección 4) — una tarde, valida todo el modelo.
3. **Rediseño de estados** (sección 3) — limpiar el ciclo de vida, convertir "reserva" en estado de pago.
4. **Automatización** — recordatorio de saldo al pasar el lote a Listo; bloqueo de "Enviado" hasta que el saldo esté pagado; notificaciones dinámicas.
5. **Embudo de mensajes** (sección 6) — se construye sobre la base ya ordenada.

> Nota de método: atacar en este orden. Empezar por los mensajes sin arreglar los estados solo hace que se pierdan pedidos más rápido.
