=== StyleLauri Order Flow ===
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 7.4
Requires plugins: woocommerce
Stable tag: 1.6.0
License: GPLv2 or later

Organiza el ciclo de vida de pedidos de StyleLauri.com: lotes de preventa, fechas de despacho, saldos por abono y puerta de despacho para Skydrops. Los estados y los correos los administra la tienda (plugin de estados + YAYMail); este plugin aporta los datos y los automatismos.

== Description ==

Este plugin resuelve el problema de raiz identificado en la operacion de StyleLauri: el "Estado del pedido" se estaba usando para codificar cinco cosas distintas a la vez (etapa del pedido, metodo de entrega, campana/lote, estado de pago y produccion), lo que hacia que la informacion colisionara y se perdiera el control de los pedidos.

= Que hace =

1. **Taxonomia "Lote de preventa"** (Productos > Lotes de preventa). Cada campana (JK-Agosto, CK-Sept, Army Bomb, etc.) es un termino, con su fecha de cierre y fecha de despacho guardadas en el propio termino -- si un lote tiene varios productos, todos comparten la misma fecha automaticamente. Un producto sin lote asignado es stock inmediato.

2. **Snapshot en el pedido**. Al hacer checkout (o editar un pedido), se calcula que lotes toca ese pedido y la fecha de despacho MAS TARDIA entre ellos. Esa fecha es la que se comunica al cliente y la que bloquea el paso a "Enviado". Si el pedido mezcla stock inmediato + preventa, sigue apareciendo en el filtro de produccion de cada lote que toca.

3. **Roles de ciclo de vida SIN estados propios**. La tienda crea sus estados (Abono produccion, Preventa, Preparacion, Abono Pendiente, Merch Lista...); en StyleLauri > Ajustes se indica que estado cumple cada rol del flujo (abono / produccion / listo / enviado). Un rol sin asignar desactiva sus automatismos.

4. **Columnas y filtro por lote** en el listado de pedidos (funciona con HPOS y con el listado legacy). Se puede filtrar "todos los pedidos del lote JK-Agosto" sin importar en que estado esten.

5. **Saldo por abono**. Campo de "monto abonado" dentro del panel de datos del pedido; el saldo pendiente se calcula solo. El pedido NO puede pasar a "Enviado" mientras tenga saldo pendiente -- si se intenta, se revierte automaticamente al estado anterior con una nota explicando por que.

6. **Datos para los correos (sin correos propios)**. Los correos los maneja la tienda (plugin de estados + YAYMail). Este plugin aporta: filas de abonos y saldo pendiente en la tabla de totales de TODO correo de WooCommerce, y metadatos insertables en plantillas (_slo_saldo_pendiente, _slo_monto_abonado, _slo_fecha_despacho, _slo_guia_envio, _slo_lotes_pedido). Ademas expone el hook 'slo_saldo_reminder' cuando un pedido entra a Preparacion con saldo.

7. **Abono Reserva en el checkout**. Si el carrito tiene productos de preventa (con lote asignado), aparece un checkbox para pagar solo un porcentaje HOY (configurable, por defecto 50%). El resto queda como saldo pendiente en el pedido: el pedido pasa solo a "Abono parcial" al confirmarse el pago, el saldo alimenta el guard de "Enviado" y el recordatorio, y el email nativo de "Procesando" se suprime para no duplicar avisos. Requiere el checkout clasico (shortcode); el Checkout Block no ejecuta estos hooks.

8. **Menu propio "StyleLauri"** en el admin: Ajustes (porcentaje y textos del abono), acceso directo a Lotes de preventa y al listado de Pedidos.

9. **Boton "Marcar saldo como pagado"** en el panel del pedido: con confirmacion, deja el saldo en 0 y registra una nota con quien lo hizo.

10. **Accion en lote "Recalcular lote(s) y fecha de despacho"** en el listado de pedidos: backfill para pedidos creados antes del plugin o antes de asignar lotes (los pedidos viejos muestran un guion en la columna Lote(s) hasta recalcularse).

= Requisitos =

* WooCommerce activo.
* Pensado para HPOS (High-Performance Order Storage), pero las columnas y el filtro tambien funcionan si el sitio usa el listado de pedidos legacy.

= Como usarlo =

1. Sube la carpeta `stylelauri-order-flow` a `wp-content/plugins/` y activa el plugin.
2. Ve a Productos > Lotes de preventa y crea un termino por campana (ej. "JK-Agosto"), con su fecha de cierre y de despacho.
3. En cada producto de preventa, asigna el lote correspondiente en la caja de "Lotes de preventa" (igual que Categorias). Los productos de stock inmediato no llevan ningun lote.
4. Los pedidos nuevos calculan su lote y fecha automaticamente. Revisa las columnas "Lote(s)" y "Despacho" en WooCommerce > Pedidos, y filtra por lote con el dropdown de arriba del listado.
5. Registra abonos con el boton "Abonar" del panel del pedido (quedan en el historial con fecha y usuario). El "Numero de guia" se guarda en el mismo panel y queda disponible como metadato para las plantillas de YAYMail.
6. En StyleLauri > Ajustes, mapea tus estados a los cuatro roles del flujo y configura el porcentaje del Abono Reserva.

== Pendiente / siguientes pasos sugeridos ==

* Exportador: agregar las columnas de lote/fecha/saldo al exportador propio de la tienda usando `SLO_Order_Snapshot::get_order_lotes()`, `get_order_fecha_despacho()` y `SLO_Order_Balance::get_saldo_pendiente()` (o el meta `_slo_saldo_pendiente`).
* Politica de carrito mixto (stock inmediato + preventa en el mismo pedido): el plugin ya soporta el escenario tecnicamente (fecha gobernante = la mas tardia), pero falta decidir si se permite mezclar en el carrito o se separa en el checkout.
* Validado en WordPress local (wp-demo, WooCommerce + PHP 8.3) con suite de 40+ checks. Prueba visual del listado y checkout real en staging de Hostinger antes de produccion.

== Changelog ==

= 1.6.0 =
* REGLA ABSOLUTA: con la puerta de despacho activa, un pedido con saldo sin pagar NUNCA queda en Procesando (Merch Lista), venga de donde venga (pasarela, movimiento manual, cualquier origen). Si el rol "Saldo Pendiente" esta mapeado, el pedido se REDIRIGE alli (y el correo de ese estado cobra al cliente); si no, se revierte al estado anterior con nota.
* Cambio de orden del router: una preventa pagada entra PRIMERO al embudo ("Abono Produccion"), aunque tenga saldo -- el saldo se cobra despues de Preparacion, como define el organigrama operativo.
* El avance automatico a Merch Lista con saldo 0 ahora tambien aplica desde "Saldo Pendiente" (antes solo desde Preparacion). Si el pedido es preventa y aun no paso por Preparacion, el router lo devuelve al embudo.
* Ajustes: roles renombrados a la nomenclatura operativa (Saldo Pendiente, Abono Produccion, Preparacion, Despacho) con los slugs recomendados en la ayuda de cada rol.

= 1.5.0 =
* Cambio: se eliminan los tres correos propios del plugin (Abono recibido, Recordatorio de saldo, Enviado). Los correos los maneja la tienda con su plugin de estados + YAYMail. El plugin sigue aportando: filas de abonos/saldo en la tabla de totales de todo correo, metadatos insertables en plantillas y el hook 'slo_saldo_reminder'.
* Nuevo: el saldo pendiente se persiste como meta del pedido (_slo_saldo_pendiente), refrescado con cada abono, para poder insertarlo en plantillas de YAYMail. La logica interna sigue usando el calculo en vivo.
* Ajustes: textos de los roles actualizados (sin menciones a correos del plugin); rol "enviado" documentado como opcional si el despacho se maneja aparte.

= 1.4.0 =
* Cambio grande: el plugin YA NO crea estados de pedido. Los estados los administra la tienda (con su propio plugin de estados); en StyleLauri > Ajustes solo se indica que estado cumple cada ROL del flujo (abono / produccion / preparacion-listo / enviado). Un rol "Sin asignar" desactiva sus automatismos sin romper nada. Los estados wc-slo-* desaparecen: si habia pedidos en esos estados, moverlos a los nuevos antes de actualizar.
* Nuevo: los abonos aparecen como filas informativas en la tabla de totales del pedido (correos de WooCommerce, pagina "pedido recibido" y "mi cuenta"), con fecha, mas la fila de "Saldo pendiente". NO se tocan los totales reales: un fee positivo tipo "cuota" inflaria el total del pedido (asi paso en la prueba manual: total quedo en 110.000 en vez de 60.000).
* Cambio: el panel de abonos del pedido queda compacto (historial + Venta/Abonado/Saldo en una linea + boton Abonar + guia); el detalle largo se elimino. El registro de cada abono sigue quedando como nota del pedido.

= 1.3.0 =
* Fix: el saldo pendiente solo aplica a pedidos que participan del sistema de abonos (con abono registrado o Abono Reserva del checkout). Antes, un pedido normal pagado completo daba saldo = total (nadie habia registrado abonos) y el guard podia bloquear su paso a "Enviado". Tambien corrige la contraentrega.
* Nuevo: puerta de despacho para integraciones tipo Skydrops (activable en Ajustes, default ON). "Procesando" queda reservado para pedidos pagados completos y despachables YA: los pagos que la pasarela (ej. Wompi) mande a Procesando se reubican solos (saldo pendiente -> Abono parcial; preventa pagada -> En produccion; stock inmediato pagado -> se queda). Cuando un pedido en "Listo" queda con saldo 0, avanza solo a Procesando. El paso manual a Procesando con saldo pendiente se bloquea igual que "Enviado". El email nativo de Procesando se suprime cuando el pedido fue reubicado.

= 1.2.0 =
* Nuevo: historial de abonos en el pedido. Cada abono queda registrado con fecha, monto, origen (manual / checkout / pago de saldo) y usuario. Se registra con el boton "Abonar" (o guardando el pedido con un monto en el campo); un valor negativo corrige un abono mal digitado. El abono del checkout entra solo como primera entrada.
* Cambio: el campo editable "Monto abonado" desaparece -- el total abonado ahora es la suma del historial. Los pedidos con monto viejo lo conservan como entrada "Abono previo" al registrar el siguiente abono.
* Cambio: "Marcar saldo como pagado" ahora registra un abono por el saldo restante en el historial (con fecha y usuario) en vez de solo ajustar el total.
* Nuevo: mapeo de estados en StyleLauri > Ajustes. Cada rol del ciclo de vida (Abono parcial, En produccion, Listo, Enviado) puede usar el estado del plugin o mapearse a un estado ya existente en la tienda. Si un rol se mapea a un estado existente, el estado del plugin se deja de registrar: no se acumulan estados duplicados. Todos los correos, el guard de saldo y el candado del snapshot siguen al estado mapeado.

= 1.1.0 =
* Nuevo: Abono Reserva integrado al plugin (antes era un snippet suelto). Deteccion por lote de preventa (ya no por categoria "preventa"), porcentaje configurable, y el saldo ahora lo calcula el mismo modulo de saldo del plugin (el calculo del snippet original estaba roto: restaba mal y daba el costo del envio como saldo).
* Nuevo: menu propio "StyleLauri" con Ajustes (porcentaje/textos del abono), Lotes de preventa y Pedidos.
* Nuevo: boton "Marcar saldo como pagado" en el panel del pedido, con confirmacion y nota de auditoria.
* Nuevo: el pedido pagado con Abono Reserva pasa automaticamente a "Abono parcial" al confirmarse el pago, y se suprime el email nativo de "Procesando" para ese caso.
* Nuevo: accion en lote "Recalcular lote(s) y fecha de despacho" para pedidos anteriores al plugin; la columna Lote(s) ahora distingue "sin calcular" (guion) de "Stock inmediato".
* IMPORTANTE al actualizar: eliminar el snippet suelto de Abono Reserva (Code Snippets / functions.php) -- si queda activo, el descuento se aplicaria dos veces.

= 1.0.1 =
* Fix: el correo de "Enviado" ya no se manda si el guard de saldo revirtio la transicion (antes se enviaba aunque el despacho quedara bloqueado).
* Fix: el correo de "Abono recibido" ya no se re-envia cuando el pedido vuelve de "Enviado" a "Abono parcial" (reversion del guard o manual), solo en abonos nuevos.
* Fix: el recordatorio de saldo ya no se duplica cuando el guard revierte un despacho bloqueado de vuelta a "Listo".
* Fix: los emails custom usaban un metodo inexistente (wrap_message vive en el mailer, no en WC_Email) -- fallaban con error fatal al dispararse.
* Fix: el placeholder {order_number} del asunto ahora se reemplaza por el numero real del pedido.
* Fix: el candado del snapshot en estado "Enviado" nunca aplicaba (comparaba con prefijo wc- contra un estado sin prefijo) -- una edicion tardia podia cambiar la fecha prometida.
* Fix: se fuerza la carga del mailer de WooCommerce antes de disparar los triggers de email custom (sin esto, los correos podian no salir segun el contexto).
* Fix: el snapshot de lote/fecha ahora tambien se calcula en pedidos creados con el Checkout Block (Store API), no solo con el checkout clasico.
* Nuevo: campo "Numero de guia" en el panel del pedido; se incluye en el correo de "Enviado" para envios a domicilio.
* Limpieza: codigo muerto en el snapshot de variaciones y helpers de estado sin uso.

= 1.0.0 =
* Primera version: taxonomia de lotes, snapshot de pedido, estados de ciclo de vida, columnas/filtro admin, saldo por abono, emails dinamicos por metodo de envio.
