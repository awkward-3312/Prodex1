@extends('setup.main')
@section('content')
<div class="row">
   <div class="col-12 text-center mt-3">
      <ul class="progressbar">
         <li class="active"><a href="/setup">Requisitos del servidor</a></li>
         <li class="active"><a href="/setup/step-1">Configuración</a></li>
         <li class="active"><a href="/setup/step-2">Base de datos</a></li>
         <li>Resumen</li>
      </ul>
   </div>
</div>
<div class="row mt-3 p-5">
   <div class="col-12">
      <form id="dbform" action="{{route('setupStep2')}}" method="post">
         @csrf
         <div id="errormsg"></div>
            <div id="db_settings" class="form-group"></div>
                <label for="app_env">Tipo de base de datos</label>
                <span class="tip" title="Selecciona el tipo de base de datos">
                    <i class="fa fa-question-circle" aria-hidden="true"></i></span>
                <select class="form-control" id="db_connection" name="db_connection">
                    <option value="mysql">MySQL</option>
                </select>
                <label for="app_name" class="mt-1" id="db_host_label">Servidor de base de datos</label>
                    <span class="tip" id="db1tooltip" title="Dirección IP o dominio donde se encuentra el servidor de base de datos. En desarrollo local normalmente es 127.0.0.1.">
                        <i class="fa fa-question-circle" aria-hidden="true"></i>
                    </span>
                <input type="text" class="form-control" id="db_host" name="db_host" placeholder="127.0.0.1" required="" value="{{$data["DB_HOST"]}}">
                <label for="app_name" class="mt-1" id="db_port_label">Puerto de base de datos</label>
                     <span class="tip" id="db2tooltip" title="Puerto en el que funciona la base de datos">
                         <i class="fa fa-question-circle" aria-hidden="true"></i>
                    </span>
            <input type="text" class="form-control" id="db_port" name="db_port" placeholder="3306" required="" value="{{$data["DB_PORT"]}}">
            <label for="app_name" class="mt-1" id="db_database_label">Nombre de la base de datos</label>
                <span class="tip" title="Nombre de la base de datos">
                    <i class="fa fa-question-circle" aria-hidden="true"></i>
                </span>
            <input type="text" class="form-control" id="db_database" name="db_database" placeholder="Nombre de la base de datos" required="">
            <label for="app_name" class="mt-1" id="db_username_label">Usuario de la base de datos</label>
                <span class="tip" id="db3tooltip" title="Usuario utilizado para conectarse a la base de datos">
                    <i class="fa fa-question-circle" aria-hidden="true"></i>
                 </span>
            <input type="text" class="form-control" id="db_username" name="db_username" placeholder="Usuario" required="" value="{{$data["DB_USERNAME"]}}">
            <label for="app_name" class="mt-1" id="db_password_label">Contraseña de la base de datos</label>
                <span class="tip" id="db4tooltip" title="Contraseña utilizada para conectarse a la base de datos">
                    <i class="fa fa-question-circle" aria-hidden="true"></i>
                </span>
             <input type="text" class="form-control" id="db_password" name="db_password" placeholder="Contraseña" required="" value="{{$data["DB_PASSWORD"]}}">
            <a id="testdb" class="btn btn-dark mb-2 form-control mt-2 text-white">Probar conexión
                <i class="fa fa-question-circle-o "></i></a>
            <div class="row">
                <div class="col-12 col-md-6">
                 <a href="/setup/step-1" class="btn btn-outline-danger mb-2"><i class="fa fa-angle-left"></i> Paso anterior</a>
                </div>
                <div class="col-12 col-md-6">
                <button type="submit" class="btn btn-outline-danger mb-2 float-md-right next_step d-none">Siguiente paso <i class="fa fa-angle-right"></i></button>
                </div>
            </div>
        </form>
    </div>
    </div>
</div>
@endsection
