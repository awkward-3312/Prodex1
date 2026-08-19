@extends('setup.main')
@section('content')
<meta name="csrf_token" content="{{ csrf_token() }}" />


    <div class="row">
        <div class="col-12 text-center mt-3">
            <ul class="progressbar">
                <li class="active">Requisitos del servidor</li>
                <li class="active"><a href="/setup">Configuración</a></li>
                <li class="active"><a href="/setup/step-2">Base de datos</a></li>
                <li class="active"><a href="/setup/step-3">Resumen</a></li>
            </ul>
        </div>
    </div>

    <div class="row mt-3">
        <div class="loader d-none">Cargando...</div>
    </div>

    <div class="row mt-3 p-5 d-block" id="content">

        <div class="col-12">

            <form action="{{route('lastStep')}}" method="post">
                @csrf

                <h2 class="mb-5">¿Deseas aplicar esta configuración?</h2>

                <div id="tochange">

                @if($data['APP_NAME'] != 'old')
                    <div class="form-group">
                        <div class="row">
                            <div class="col-12 col-md-6 text-truncate">Nombre de la aplicación</div>

                            <div class="col-12 col-md-6 text-truncate"> {{ $data['APP_NAME'] }}</div>
                        </div>
                    </div>
                @endif

                @if($data['APP_KEY'] != 'old')
                <div class="form-group">
                    <div class="row">
                        <div class="col-12 col-md-6 text-truncate font-weight-bold">Clave de la aplicación</div>

                        <div class="col-12 col-md-6 text-truncate"> {{ $data['APP_KEY'] }}</div>
                    </div>
                </div>
                @endif

                @if($data['APP_DEBUG'] != 'old')
                    <div class="form-group">
                        <div class="row">
                            <div class="col-12 col-md-6 text-truncate">Modo de depuración</div>

                            <div class="col-12 col-md-6 text-truncate"> {{ $data['APP_DEBUG'] }}</div>
                        </div>
                    </div>
                @endif


                @if($data['DB_HOST'] != 'old')
                    <div class="form-group">
                        <div class="row">
                            <div class="col-12 col-md-6 text-truncate">Servidor de base de datos</div>

                            <div class="col-12 col-md-6 text-truncate"> {{ $data['DB_HOST'] }}</div>
                        </div>
                    </div>
                @endif


                @if($data['DB_DATABASE'] != 'old')
                    <div class="form-group">
                        <div class="row">
                            <div class="col-12 col-md-6 text-truncate">Base de datos seleccionada</div>

                            <div class="col-12 col-md-6 text-truncate"> {{ $data['DB_DATABASE']}}</div>
                        </div>
                    </div>
                @endif

                @if($data['DB_USERNAME'] != 'old')
                    <div class="form-group">
                        <div class="row">
                            <div class="col-12 col-md-6 text-truncate">Usuario de la base de datos</div>

                            <div class="col-12 col-md-6 text-truncate"> {{ $data['DB_USERNAME'] }}</div>
                        </div>
                    </div>
                @endif


                </div>
                <div class="row mt-5">
                    <div class="col-12 col-md-6 text-truncate">
                        <a href="/setup/step-2" class="btn btn-outline-danger mb-2"><i class="fa fa-angle-left"></i> Paso anterior</a>
                    </div>
                    <div class="col-12 col-md-6 text-truncate">
                        <button type="submit" class="btn btn-success mb-2 btn-block" id="lastStep">Confirmar <i class="fa fa-check"></i></button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
