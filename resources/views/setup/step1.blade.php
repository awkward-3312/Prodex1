@extends('setup.main')
@section('content')

    <div class="row">
        <div class="col-12 text-center mt-3">
            <ul class="progressbar">
                <li class="active"><a href="/setup">Requisitos del servidor</a></li>
                <li class="active"><a href="/setup/step-1">Configuración</a></li>
                <li>Base de datos</li>
                <li>Resumen</li>
            </ul>
        </div>
    </div>

    <div class="row mt-3 p-5">
        <div class="col-12">
            <form action="{{route('setupStep1')}}" method="post">
                @csrf
                <div class="form-group">
                    <label for="app_name">Nombre de la aplicación</label> <span class="tip" title="Este es el nombre de tu aplicación"><i class="fa fa-question-circle" aria-hidden="true"></i></span>
                    <input type="text" class="form-control" id="app_name" name="app_name" placeholder="PRODEX" value="{{$data['APP_NAME']}}" autofocus>
                </div>

                <div class="form-group">
                    <label for="app_env">Entorno</label> <span class="tip" title="Selecciona el entorno donde se ejecutará la aplicación. Para desarrollo normalmente se usa local."><i class="fa fa-question-circle" aria-hidden="true"></i></span>
                    <select class="form-control" id="app_env" name="app_env">
                        @if($data['APP_ENV'] == 'local')
                            <option value="local">Local</option>
                            <option value="testing">Pruebas</option>
                            <option value="production">Producción</option>
                        @elseif($data['APP_ENV'] == 'testing')
                            <option value="testing">Pruebas</option>
                            <option value="local">Local</option>
                            <option value="production">Producción</option>
                        @else
                            <option value="production">Producción</option>
                            <option value="testing">Pruebas</option>
                            <option value="local">Local</option>
                        @endif

                    </select>
                </div>

                <div class="form-group">
                    <label for="app_debug">Modo de depuración</label> <span class="tip" title="APP_DEBUG muestra información detallada de errores y se recomienda solo para desarrollo."><i class="fa fa-question-circle" aria-hidden="true"></i></span>
                    <select class="form-control" id="app_debug" name="app_debug">
                        @if($data['APP_DEBUG'] == 'true')
                        <option value="true">Activado</option>
                        <option value="false">Desactivado</option>
                        @else
                            <option value="false">Desactivado</option>
                            <option value="true">Activado</option>
                        @endif
                    </select>
                </div>

                <div class="form-group">
                    <label for="app_url">URL de la aplicación</label> <span class="tip" title="URL completa donde se accederá a la aplicación (Ej. https://tudominio.com)"><i class="fa fa-question-circle" aria-hidden="true"></i></span>
                    <input type="url" class="form-control" id="app_url" name="app_url" placeholder="https://tudominio.com" value="{{ old('app_url', session('env.APP_URL') ?: request()->getSchemeAndHttpHost()) }}" required>
                    <small class="form-text text-muted">Ingresa el nombre de dominio, no la dirección IP del servidor.</small>
                </div>

                <div class="form-group">
                    <label for="app_name">Clave de la aplicación</label> <span class="tip" title="La clave de la aplicación es una cadena base64 única. Puedes generar una nueva para esta instalación."><i class="fa fa-question-circle" aria-hidden="true"></i></span>
                    <input type="text" class="form-control" id="app_key" name="app_key" value="{{$data['APP_KEY']}}" placeholder="Presiona el botón para generar una clave" readonly>

                    <div class="col-12 col-md-6">
                        <button class="btn btn-outline-warning mt-3" id="generate_key" title="Generar">Generar clave</button>
                    </div>
                </div>

                <div class="row">
                    <div class="col-12 col-md-6">
                         <a href="/setup" class="btn btn-outline-danger mt-3"><i class="fa fa-angle-left"></i> Paso anterior</a>
                    </div>
                   <div class="col-6 col-md-6">
                        <button type="submit" id="next" class="btn btn-outline-danger mt-3 float-md-right">Siguiente paso <i class="fa fa-angle-right"></i></button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
