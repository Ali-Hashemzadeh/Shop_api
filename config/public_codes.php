<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Public code namespace
    |--------------------------------------------------------------------------
    |
    | The store-wide prefix that opens every customer-facing code, e.g. the `bd`
    | in `bdo-Q8M2XC`. It is combined with a one-character entity segment (see
    | App\Support\PublicCodeEntity) by App\Support\PublicCodeGenerator, which is
    | the only place a full prefix is ever assembled.
    |
    | REQUIRED. There is no fallback on purpose: silently defaulting would mint
    | codes under the wrong prefix, and a public identifier cannot be un-issued.
    | A missing or malformed value raises PublicCodeGenerationException.
    |
    | Changing it affects NEWLY generated records only. Every stored code keeps
    | the namespace it was issued under, and all of them stay valid.
    |
    */

    'namespace' => env('PUBLIC_CODE_NAMESPACE'),

    /*
    |--------------------------------------------------------------------------
    | Generation attempts
    |--------------------------------------------------------------------------
    |
    | How many candidates to try before giving up. With 31^6 (~887 million)
    | suffixes per prefix, exhausting this budget means the existence check or
    | the keyspace is broken, not that we were unlucky — so it throws rather
    | than degrading to a weaker identifier.
    |
    | The suffix length (6) and the human-safe alphabet
    | (23456789ABCDEFGHJKMNPQRSTUVWXYZ — no 0/O/1/I/L) are fixed contract and
    | live as constants on PublicCodeGenerator, not here: changing either would
    | make already-issued codes ambiguous against new ones.
    |
    */

    'max_attempts' => 20,

];
