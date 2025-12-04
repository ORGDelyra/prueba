<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class ImageUploadController extends Controller
{
    /**
     * Sube un archivo recibido al endpoint de Cloudinary usando un upload preset (unsigned).
     * Si prefieres usar server-side signed uploads, instala y configura el SDK oficial.
     */
    public function upload(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:51200', // max 50MB
            'type' => 'nullable|in:perfil,comprobante,producto',
            'upload_preset' => 'nullable|string'
        ]);

        $file = $request->file('file');

        $cloudName = env('CLOUDINARY_CLOUD_NAME');

        // Resolución del upload preset según el tipo
        $providedPreset = $request->input('upload_preset');
        $type = $request->input('type');

        $presetFromType = null;
        if ($type === 'perfil') {
            $presetFromType = env('CLOUDINARY_UPLOAD_PRESET_PERFIL');
        } elseif ($type === 'comprobante') {
            $presetFromType = env('CLOUDINARY_UPLOAD_PRESET_COMPROBANTE');
        } elseif ($type === 'producto') {
            $presetFromType = env('CLOUDINARY_UPLOAD_PRESET_PRODUCTO');
        }

        $uploadPreset = $providedPreset ?: ($presetFromType ?: env('CLOUDINARY_UPLOAD_PRESET'));

        if (empty($cloudName) || empty($uploadPreset)) {
            return response()->json(['mensaje' => 'Cloudinary no configurado en el servidor (cloud name o upload preset faltante)'], 500);
        }

        try {
            $url = "https://api.cloudinary.com/v1_1/{$cloudName}/image/upload";

            // Log para debugging
            Log::info('Upload request', [
                'url' => $url,
                'preset' => $uploadPreset,
                'type' => $type,
                'filename' => $file->getClientOriginalName()
            ]);

            // Usar cURL directamente para unsigned upload (más control, sin headers extra)
            $ch = curl_init();

            $postData = [
                'file' => new \CURLFile($file->getPathname(), $file->getMimeType(), $file->getClientOriginalName()),
                'upload_preset' => $uploadPreset
            ];

            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $postData,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => env('CLOUDINARY_VERIFY_SSL', false), // false en local, true en prod
                CURLOPT_SSL_VERIFYHOST => env('CLOUDINARY_VERIFY_SSL', false) ? 2 : 0,
                CURLOPT_VERBOSE => true, // Debug detallado
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            /** @phpstan-ignore-next-line */
            curl_close($ch);

            if ($error) {
                Log::error('Cloudinary cURL error: ' . $error);
                return response()->json(['mensaje' => 'Error de conexión con Cloudinary', 'error' => $error], 500);
            }

            $body = json_decode($response, true);

            if ($httpCode !== 200) {
                Log::error('Cloudinary upload failed: ' . $response);
                return response()->json([
                    'mensaje' => 'Error subiendo la imagen a Cloudinary',
                    'raw' => $response,
                    'status' => $httpCode
                ], 500);
            }

            return response()->json([
                'secure_url' => $body['secure_url'] ?? null,
                'public_id' => $body['public_id'] ?? null,
                'format' => $body['format'] ?? null,
                'width' => $body['width'] ?? null,
                'height' => $body['height'] ?? null,
            ], 200);

        } catch (\Exception $e) {
            Log::error('Cloudinary upload error: ' . $e->getMessage());
            return response()->json(['mensaje' => 'Error subiendo la imagen', 'error' => $e->getMessage()], 500);
        }
    }
}
