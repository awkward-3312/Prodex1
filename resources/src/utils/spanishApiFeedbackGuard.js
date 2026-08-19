import axios from 'axios';

const EXACT_MESSAGES = {
  'Settings record not found.': 'No se encontró el registro de configuración.',
  'Settings not found': 'No se encontró la configuración.',
  'Record not found.': 'No se encontró el registro.',
  'Resource not found.': 'No se encontró el recurso.',
  'No data found.': 'No se encontraron datos.',
  'Invalid request.': 'La solicitud no es válida.',
  'Validation failed.': 'La validación falló.',
  'Permission denied.': 'Permiso denegado.',
  'Access denied.': 'Acceso denegado.',
  'Operation failed.': 'No se pudo completar la operación.',
  'Request failed.': 'No se pudo completar la solicitud.',
  'Internal server error.': 'Ocurrió un error interno del servidor.',
  'Created successfully.': 'Creado correctamente.',
  'Updated successfully.': 'Actualizado correctamente.',
  'Deleted successfully.': 'Eliminado correctamente.',
  'Saved successfully.': 'Guardado correctamente.',
  'Operation completed successfully.': 'Operación completada correctamente.'
};

function translateMessage(value) {
  if (typeof value !== 'string') return value;
  const original = value.trim();
  if (!original) return value;
  if (EXACT_MESSAGES[original]) return EXACT_MESSAGES[original];

  if (/^settings? (record )?not found\.?$/i.test(original)) return 'No se encontró la configuración.';
  if (/^record not found\.?$/i.test(original)) return 'No se encontró el registro.';
  if (/^resource not found\.?$/i.test(original)) return 'No se encontró el recurso.';
  if (/^no (records?|data|results?) found\.?$/i.test(original)) return 'No se encontraron resultados.';
  if (/^permission denied\.?$/i.test(original) || /^access denied\.?$/i.test(original)) return 'Acceso denegado.';
  if (/^invalid request\.?$/i.test(original)) return 'La solicitud no es válida.';
  if (/^validation failed\.?$/i.test(original)) return 'La validación falló.';
  if (/^(failed|unable|could not) to\b/i.test(original)) return 'No se pudo completar la operación.';
  if (/^something went wrong\.?$/i.test(original) || /^an error occurred\.?$/i.test(original)) return 'Ocurrió un error.';
  if (/^saved successfully\.?$/i.test(original)) return 'Guardado correctamente.';
  if (/^created successfully\.?$/i.test(original)) return 'Creado correctamente.';
  if (/^updated successfully\.?$/i.test(original)) return 'Actualizado correctamente.';
  if (/^deleted successfully\.?$/i.test(original)) return 'Eliminado correctamente.';

  return value;
}

function translatePayload(payload) {
  if (!payload || typeof payload !== 'object') return payload;

  if (typeof payload.message === 'string') payload.message = translateMessage(payload.message);
  if (typeof payload.error === 'string') payload.error = translateMessage(payload.error);

  if (payload.errors && typeof payload.errors === 'object') {
    Object.keys(payload.errors).forEach(key => {
      const value = payload.errors[key];
      if (Array.isArray(value)) {
        payload.errors[key] = value.map(item => translateMessage(item));
      } else if (typeof value === 'string') {
        payload.errors[key] = translateMessage(value);
      }
    });
  }

  return payload;
}

export function installSpanishApiFeedbackGuard() {
  if (typeof window === 'undefined') return;
  if (window.__prodexSpanishApiFeedbackGuard) return;

  axios.interceptors.response.use(
    response => {
      if (response && response.data) translatePayload(response.data);
      return response;
    },
    error => {
      if (error && error.response && error.response.data) {
        translatePayload(error.response.data);
      } else if (error && typeof error === 'object') {
        translatePayload(error);
      }
      return Promise.reject(error);
    }
  );

  window.__prodexSpanishApiFeedbackGuard = true;
}
