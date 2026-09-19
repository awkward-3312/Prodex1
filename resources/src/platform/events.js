/**
 * Bus de eventos de PRODEX, independiente de Vue.
 *
 * Reproduce la semántica de los métodos de evento de Vue 2 que usa el producto (`$on`, `$once`, `$off`, `$emit`) para que
 * `window.Fire` (hasta hoy un `new Vue()`) pueda ser este objeto sin tocar a sus ~330 consumidores:
 *  - `$on(evento | [eventos], fn)` registra en orden; el mismo `fn` puede registrarse varias veces.
 *  - `$once` se auto-elimina ANTES de ejecutar el listener; `$off(evento, fn)` también acepta el `fn` original de un `$once`.
 *  - `$off()` quita todo; `$off(evento)` quita todos los de ese evento; `$off(evento, fn)` quita UNA instancia (la última
 *    registrada, como Vue).
 *  - `$emit(evento, ...args)` llama a una COPIA de la lista (los listeners pueden desuscribirse durante la emisión) y un
 *    listener que lanza un error no impide que corran los demás (Vue captura y reporta cada error).
 *  - Todos los métodos devuelven el bus (encadenables), como en Vue.
 *
 * Para código nuevo hay una API sin `$`: `on()` devuelve la función para desuscribirse.
 */

const defaultOnError = (error, event) => {
  // Igual que Vue en navegador: el error se reporta en consola y no interrumpe al resto de listeners.
  console.error(`[PRODEX events] el listener de "${String(event)}" lanzó un error`, error);
};

export function createEventBus({ onError = defaultOnError } = {}) {
  /** @type {Map<string|symbol, Function[]>} */
  const registry = new Map();

  const bus = {
    $on(event, fn) {
      if (Array.isArray(event)) {
        event.forEach((e) => bus.$on(e, fn));
        return bus;
      }
      if (typeof fn !== 'function') throw new TypeError('El listener de un evento debe ser una función');
      if (!registry.has(event)) registry.set(event, []);
      registry.get(event).push(fn);
      return bus;
    },

    $once(event, fn) {
      if (typeof fn !== 'function') throw new TypeError('El listener de un evento debe ser una función');
      function wrapper(...args) {
        bus.$off(event, wrapper);
        return fn.apply(bus, args);
      }
      wrapper.fn = fn;
      bus.$on(event, wrapper);
      return bus;
    },

    $off(event, fn) {
      if (arguments.length === 0) {
        registry.clear();
        return bus;
      }
      if (Array.isArray(event)) {
        event.forEach((e) => bus.$off(e, fn));
        return bus;
      }
      const listeners = registry.get(event);
      if (!listeners) return bus;
      if (!fn) {
        registry.delete(event);
        return bus;
      }
      for (let i = listeners.length - 1; i >= 0; i -= 1) {
        if (listeners[i] === fn || listeners[i].fn === fn) {
          listeners.splice(i, 1);
          break;
        }
      }
      if (listeners.length === 0) registry.delete(event);
      return bus;
    },

    $emit(event, ...args) {
      const listeners = registry.get(event);
      if (listeners) {
        for (const listener of listeners.slice()) {
          try {
            listener.apply(bus, args);
          } catch (error) {
            onError(error, event);
          }
        }
      }
      return bus;
    },

    // --- API para código nuevo -------------------------------------------------------------------------------
    /** Suscribe y devuelve la función que cancela la suscripción. */
    on(event, fn) {
      bus.$on(event, fn);
      return () => bus.$off(event, fn);
    },
    once(event, fn) {
      bus.$once(event, fn);
      return () => bus.$off(event, fn);
    },
    off(event, fn) {
      return bus.$off(event, fn);
    },
    emit(event, ...args) {
      return bus.$emit(event, ...args);
    },
    /** Cantidad de listeners de un evento (útil en pruebas y para detectar fugas). */
    listenerCount(event) {
      const listeners = registry.get(event);
      return listeners ? listeners.length : 0;
    },
    /** Elimina todos los listeners. */
    clear() {
      registry.clear();
      return bus;
    },
  };

  return bus;
}

/** Bus compartido de la aplicación. `window.Fire` apunta a este mismo objeto (adaptador temporal de compatibilidad). */
export const events = createEventBus();
