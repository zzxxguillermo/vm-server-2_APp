<?php

namespace App\Console\Commands;

use App\Enums\UserType;
use App\Models\SocioPadron;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class ProfessorsSeedFromPadron extends Command
{
    protected $signature = 'professors:seed-from-padron';
    protected $description = 'Crea o actualiza profesores desde socios_padron (por DNI) con password temporal';

    public function handle(): int
    {
        $entries = [
            [
                'name' => 'Santiago Oscar Espindola',
                'dni' => '43802149',
                'email' => 'santiagoespindola1701@gmail.com',
            ],
            [
                'name' => 'Lautaro Hernán Palumbo',
                'dni' => '43465918',
                'email' => 'LautaroPalumbo@yahoo.com',
            ],
            [
                'name' => 'Romina Olivera',
                'dni' => '37025138',
                'email' => 'Rominasilvanaolivera@gmail.com',
            ],
        ];

        $hasUserType = Schema::hasColumn('users', 'user_type');
        $hasAccountStatus = Schema::hasColumn('users', 'account_status');

        $createdUsers = 0;
        $updatedUsers = 0;
        $assignedProfessors = 0;
        $skippedMissingPadron = 0;

        foreach ($entries as $entry) {
            $dni = trim((string) ($entry['dni'] ?? ''));
            $email = trim((string) ($entry['email'] ?? ''));

            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->error("ERROR: missing email for DNI {$dni}");
                continue;
            }

            $padron = SocioPadron::query()->where('dni', $dni)->first();
            if (!$padron) {
                $this->warn("SKIP: DNI {$dni} not found in socios_padron");
                $skippedMissingPadron++;
                continue;
            }

            $padronName = trim((string) $padron->apynom);
            $name = $padronName !== '' ? $padronName : (string) ($entry['name'] ?? '');

            $user = User::query()->where('dni', $dni)->first();
            $justCreated = false;
            $tempPassword = null;

            if (!$user) {
                $tempPassword = $this->generatePassword(14);

                $createData = [
                    'name' => $name,
                    'dni' => $dni,
                    'email' => $email,
                    'password' => Hash::make($tempPassword),
                ];

                if ($hasUserType) {
                    $createData['user_type'] = UserType::API;
                }

                if ($hasAccountStatus) {
                    $createData['account_status'] = 'active';
                }

                $user = User::create($createData);
                $justCreated = true;
                $createdUsers++;
            }

            $needsSave = false;

            if (!$justCreated) {
                if (($user->email ?? '') !== $email) {
                    $user->email = $email;
                    $needsSave = true;
                }

                if (($user->name ?? '') === '' && $name !== '') {
                    $user->name = $name;
                    $needsSave = true;
                }

                if (empty($user->password)) {
                    $tempPassword = $this->generatePassword(14);
                    $user->password = Hash::make($tempPassword);
                    $needsSave = true;
                }

                if ($needsSave) {
                    $user->save();
                    $updatedUsers++;
                }
            }

            if (!$user->is_professor) {
                $user->assignProfessorRole([
                    'notes' => 'Seeded from padron',
                ]);
                $assignedProfessors++;
            }

            if ($tempPassword !== null) {
                $this->line("OK: {$dni} {$email} temp_password={$tempPassword}");
            } else {
                $this->info("OK: {$dni} {$email} no_password_change");
            }
        }

        $this->line('--- Summary ---');
        $this->line("created_users={$createdUsers}");
        $this->line("updated_users={$updatedUsers}");
        $this->line("assigned_professors={$assignedProfessors}");
        $this->line("skipped_missing_padron={$skippedMissingPadron}");

        return self::SUCCESS;
    }

    private function generatePassword(int $length): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $maxIndex = strlen($alphabet) - 1;
        $password = '';

        for ($i = 0; $i < $length; $i++) {
            $password .= $alphabet[random_int(0, $maxIndex)];
        }

        return $password;
    }
}
