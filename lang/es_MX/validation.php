<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Mensajes de validación — español mexicano
|--------------------------------------------------------------------------
|
| CLAUDE.md exige que los mensajes de validación estén en español mexicano con
| acentuación completa: los lee un cajero en una terminal, no un desarrollador en un log.
|
| Traducción DELIBERADAMENTE PARCIAL: cubre las reglas que este proyecto usa de verdad. Las
| que falten caen al inglés por `APP_FALLBACK_LOCALE`, que es un degradado visible y sin
| riesgo — mucho mejor que un archivo enorme con traducciones automáticas que nadie revisó.
| Cada iteración añade las reglas que introduce.
|
*/

return [
    'accepted' => 'Debes aceptar :attribute.',
    'active_url' => ':attribute no es una URL válida.',
    'after' => ':attribute debe ser una fecha posterior a :date.',
    'after_or_equal' => ':attribute debe ser una fecha posterior o igual a :date.',
    'alpha' => ':attribute sólo puede contener letras.',
    'alpha_dash' => ':attribute sólo puede contener letras, números, guiones y guiones bajos.',
    'alpha_num' => ':attribute sólo puede contener letras y números.',
    'array' => ':attribute debe ser una lista.',
    'before' => ':attribute debe ser una fecha anterior a :date.',
    'before_or_equal' => ':attribute debe ser una fecha anterior o igual a :date.',

    'between' => [
        'array' => ':attribute debe tener entre :min y :max elementos.',
        'file' => ':attribute debe pesar entre :min y :max kilobytes.',
        'numeric' => ':attribute debe estar entre :min y :max.',
        'string' => ':attribute debe tener entre :min y :max caracteres.',
    ],

    'boolean' => ':attribute debe ser verdadero o falso.',
    'confirmed' => 'La confirmación de :attribute no coincide.',
    'date' => ':attribute no es una fecha válida.',
    'date_equals' => ':attribute debe ser una fecha igual a :date.',
    'date_format' => ':attribute no corresponde al formato :format.',
    'decimal' => ':attribute debe tener :decimal decimales.',
    'different' => ':attribute y :other deben ser distintos.',
    'digits' => ':attribute debe tener :digits dígitos.',
    'digits_between' => ':attribute debe tener entre :min y :max dígitos.',
    'email' => ':attribute debe ser un correo electrónico válido.',
    'ends_with' => ':attribute debe terminar con alguno de estos valores: :values.',
    'enum' => 'El valor de :attribute no es válido.',
    'exists' => 'El valor de :attribute no existe.',
    'filled' => ':attribute no puede quedar vacío.',

    'gt' => [
        'array' => ':attribute debe tener más de :value elementos.',
        'numeric' => ':attribute debe ser mayor que :value.',
        'string' => ':attribute debe tener más de :value caracteres.',
    ],
    'gte' => [
        'array' => ':attribute debe tener :value elementos o más.',
        'numeric' => ':attribute debe ser mayor o igual que :value.',
        'string' => ':attribute debe tener :value caracteres o más.',
    ],

    'image' => ':attribute debe ser una imagen.',
    'in' => 'El valor de :attribute no es válido.',
    'integer' => ':attribute debe ser un número entero.',
    'ip' => ':attribute debe ser una dirección IP válida.',
    'json' => ':attribute debe ser una cadena JSON válida.',

    'lt' => [
        'array' => ':attribute debe tener menos de :value elementos.',
        'numeric' => ':attribute debe ser menor que :value.',
        'string' => ':attribute debe tener menos de :value caracteres.',
    ],
    'lte' => [
        'array' => ':attribute no debe tener más de :value elementos.',
        'numeric' => ':attribute debe ser menor o igual que :value.',
        'string' => ':attribute debe tener :value caracteres o menos.',
    ],

    'max' => [
        'array' => ':attribute no puede tener más de :max elementos.',
        'file' => ':attribute no puede pesar más de :max kilobytes.',
        'numeric' => ':attribute no puede ser mayor que :max.',
        'string' => ':attribute no puede tener más de :max caracteres.',
    ],
    'min' => [
        'array' => ':attribute debe tener al menos :min elementos.',
        'file' => ':attribute debe pesar al menos :min kilobytes.',
        'numeric' => ':attribute debe ser al menos :min.',
        'string' => ':attribute debe tener al menos :min caracteres.',
    ],

    'mimes' => ':attribute debe ser un archivo de tipo: :values.',
    'mimetypes' => ':attribute debe ser un archivo de tipo: :values.',
    'not_in' => 'El valor de :attribute no es válido.',
    'not_regex' => 'El formato de :attribute no es válido.',
    'numeric' => ':attribute debe ser un número.',
    'present' => ':attribute debe estar presente.',
    'prohibited' => ':attribute no está permitido.',
    'prohibited_if' => ':attribute no está permitido cuando :other es :value.',
    'regex' => 'El formato de :attribute no es válido.',
    'required' => ':attribute es obligatorio.',
    'required_if' => ':attribute es obligatorio cuando :other es :value.',
    'required_unless' => ':attribute es obligatorio salvo que :other esté en :values.',
    'required_with' => ':attribute es obligatorio cuando :values está presente.',
    'required_without' => ':attribute es obligatorio cuando :values no está presente.',
    'same' => ':attribute y :other deben coincidir.',

    'size' => [
        'array' => ':attribute debe contener :size elementos.',
        'file' => ':attribute debe pesar :size kilobytes.',
        'numeric' => ':attribute debe ser :size.',
        'string' => ':attribute debe tener :size caracteres.',
    ],

    'starts_with' => ':attribute debe comenzar con alguno de estos valores: :values.',
    'string' => ':attribute debe ser texto.',
    'timezone' => ':attribute debe ser una zona horaria válida.',
    'unique' => ':attribute ya está en uso.',
    'uploaded' => 'No se pudo subir :attribute.',
    'url' => ':attribute debe ser una URL válida.',
    'ulid' => ':attribute debe ser un identificador válido.',
    'uuid' => ':attribute debe ser un identificador válido.',

    /*
    |--------------------------------------------------------------------------
    | Reglas propias del dominio
    |--------------------------------------------------------------------------
    |
    | Aquí van los mensajes de las reglas de validación de Comandia conforme cada
    | iteración las introduzca (RFC, CURP, NSS, tasa de IVA, markup…).
    |
    */
    'custom' => [],

    /*
    |--------------------------------------------------------------------------
    | Nombres de atributos
    |--------------------------------------------------------------------------
    |
    | Los nombres específicos de cada endpoint se declaran en su Form Request, que es donde
    | tienen sentido. Aquí sólo los que aparecen en muchos lugares.
    |
    */
    'attributes' => [
        'email' => 'el correo electrónico',
        'password' => 'la contraseña',
        'first_name' => 'el nombre',
        'paternal_surname' => 'el apellido paterno',
        'maternal_surname' => 'el apellido materno',
        'pin' => 'el PIN',
        'employee_code' => 'el código de empleado',
        'name' => 'el nombre',
        'code' => 'el código',
        'timezone' => 'la zona horaria',
        'rfc' => 'el RFC',
        'curp' => 'la CURP',
        'nss' => 'el NSS',
    ],
];
