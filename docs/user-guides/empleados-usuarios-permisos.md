# Empleados, usuarios, puestos y permisos

## Conceptos

PRODEX separa cuatro responsabilidades:

- **Empleado:** la persona dentro de Gestión de personal.
- **Puesto laboral:** su función organizacional, por ejemplo Cajero o Bodeguero.
- **Usuario:** la cuenta con la que entra a PRODEX.
- **Rol y permisos:** las acciones que esa cuenta puede realizar.

El puesto no sustituye al rol. PRODEX puede sugerir un rol según el puesto, pero el administrador decide los permisos finales.

## Flujo recomendado

1. Cree el empleado desde Gestión de personal.
2. Asigne empresa, departamento, sucursal, puesto y turno.
3. Si la persona necesita entrar a PRODEX, cree o vincule su usuario.
4. Desde Usuarios y accesos asigne el rol.
5. Defina sus bodegas operativas y su bodega predeterminada.
6. Active únicamente los permisos necesarios para su trabajo.

## Puestos predeterminados

PRODEX ofrece plantillas comunes como Gerente de sucursal, Administrador de sucursal, Cajero, Supervisor de caja, Servicio al cliente, Bodeguero, Encargado de inventario, Encargado de recepción, Vendedor, Compras, Contabilidad, Recursos Humanos y Mantenimiento.

También puede crear puestos personalizados.

## Principio de mínimo acceso

Asigne a cada usuario solo los permisos que necesita. Ejemplos:

- Un cajero puede consultar inventario de su sucursal, pero no ajustar existencias ni eliminar productos.
- Un bodeguero puede registrar movimientos, conteos y recepciones en sus bodegas asignadas.
- Un gerente puede supervisar la sucursal sin recibir automáticamente permisos administrativos globales.

## Alcance

Los permisos responden **qué puede hacer** el usuario. Las sucursales y bodegas responden **sobre qué datos puede hacerlo**.
