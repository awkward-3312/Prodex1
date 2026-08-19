<?php

namespace App\Http\Middleware;

use App\Models\OauthAccessTokens;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class IsActiveToken
{
    public function handle($request, Closure $next)
    {
        $user = Auth::user();
        $userSerialize = serialize($user);
        $userUnserializeArray = (array) unserialize($userSerialize);

        $arrayKeys = array_keys($userUnserializeArray);
        foreach ($arrayKeys as $value) {

            // Stocky_token is a legacy internal token property. It is kept for
            // compatibility and is not exposed as product branding in the UI.
            if (strpos($value, 'Stocky_token') !== false) {

                $userAccessTokenArray = (array) $userUnserializeArray[$value];
                $arrayAccessKeys = array_keys($userAccessTokenArray);
                foreach ($arrayAccessKeys as $arrayAccessValue) {

                    if (strpos($arrayAccessValue, 'original') !== false) {

                        $userTokenId = $userAccessTokenArray[$arrayAccessValue]['id'];
                        $checkToken = OauthAccessTokens::where([
                            ['id', '=', $userTokenId],
                            ['expires_at', '>', Carbon::now()],
                        ])->first();

                        if (! $checkToken) {
                            return response()->json([
                                'error' => true,
                                'msg' => 'La sesión ha vencido. Inicia sesión nuevamente.',
                            ]);
                        }
                    }
                }
            }
        }

        return $next($request);
    }
}
