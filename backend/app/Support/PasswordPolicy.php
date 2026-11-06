<?php

namespace App\Support;

/** Mot de passe choisi par l'utilisateur (inscription, réinitialisation) : 10 caractères au moins, avec lettres et chiffres. */
class PasswordPolicy
{
    public const MIN = 10;

    public static function rules(): array
    {
        return ['required', 'string', 'min:'.self::MIN, 'max:200', 'regex:/\pL/u', 'regex:/\d/', 'confirmed'];
    }

    public static function messages(): array
    {
        return [
            'password.required' => 'Le mot de passe est obligatoire.',
            'password.min' => 'Le mot de passe doit contenir au moins '.self::MIN.' caractères.',
            'password.max' => 'Le mot de passe est trop long.',
            'password.regex' => 'Le mot de passe doit contenir au moins une lettre et un chiffre.',
            'password.confirmed' => 'Les deux mots de passe ne correspondent pas.',
        ];
    }
}
