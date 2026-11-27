# Conectar Liberu con TARMIO (para el propietario)

Liberu creará en TARMIO una tarea por cada acción pendiente (nuevo propietario, avería, entrada…). Para eso entra en
TARMIO con un **usuario de integración** propio. Son dos pasos; ninguno activa todavía los envíos.

## 1. Crear el usuario de integración en TARMIO

1. Elige un correo **solo para esto** que puedas abrir (p. ej. `tarmio-liberu@clarto.es`). No uses el de una persona.
2. En TARMIO (`tarmio.app`), con tu cuenta: **Equipo → Invitar persona**. Email: ese correo. Empresa: **CLARTO**.
   Rol: **Manager**. **Enviar invitación**.
3. Abre la invitación en ese buzón → **Crear cuenta y aceptar invitación** y pon una contraseña larga.
   * Sin comillas simples ( ' ).
   * **No** le actives la verificación en dos pasos: con ella TARMIO no deja entrar a Liberu.
   * Que sea miembro **solo** de CLARTO.
4. Las personas que recibirán tareas deben estar en CLARTO en TARMIO **con el mismo correo** que usan en Liberu.

## 2. Guardar la contraseña en el servidor

En el servidor, desde la carpeta del panel (`/srv/clarto-staging/pwb/deploy/clarto-staging`):

```
sudo scripts/set-tarmio.sh tarmio-liberu@clarto.es
```

Te pide la contraseña (no se ve al escribirla). El script la guarda, busca la empresa CLARTO, comprueba los permisos
y te enseña los proyectos de TARMIO. Al final debe decir **«TARMIO listo. Envíos reales: desactivados»**.

Opcional:

* `sudo scripts/set-tarmio.sh --proyecto <id>` → las tareas van a ese proyecto (si no, a la bandeja de CLARTO).
* `sudo scripts/set-tarmio.sh --responsable persona@clarto.es` → quién recibe las tareas de nuevo propietario.
* `sudo scripts/set-tarmio.sh --prueba` → crea una tarea de prueba ficticia y la borra al momento.

Cuando termines, avisa: los envíos reales se activan después (`--activar`), con tu permiso.
