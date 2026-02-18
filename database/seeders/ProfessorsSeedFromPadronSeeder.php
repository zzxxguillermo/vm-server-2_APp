<?php

namespace Database\Seeders;

use App\Enums\UserType;
use App\Models\SocioPadron;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class ProfessorsSeedFromPadronSeeder extends Seeder
{
    public function run(): void
    {
        $entries = [
            [
                'name' => 'Santiago Oscar Espindola',
                'dni' => '43802149',
                'email' => 'santiagoespindola1701@gmail.com',
            ],
            [
                'name' => 'Lautaro Hernan Palumbo',
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
                $this->printLine("ERROR: missing email for DNI {$dni}");
                continue;
            }

            $padron = SocioPadron::query()->where('dni', $dni)->first();
            if (!$padron) {
                $this->printLine("SKIP: DNI {$dni} not found in socios_padron");
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
                $this->printLine("OK: {$dni} {$email} temp_password={$tempPassword}");
            } else {
                $this->printLine("OK: {$dni} {$email} no_password_change");
            }
        }

        $this->printLine('--- Summary ---');
        $this->printLine("created_users={$createdUsers}");
        $this->printLine("updated_users={$updatedUsers}");
        $this->printLine("assigned_professors={$assignedProfessors}");
        $this->printLine("skipped_missing_padron={$skippedMissingPadron}");
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

    private function printLine(string $message): void
    {
        if ($this->command) {
            $this->command->line($message);
            return;
        }

        echo $message . PHP_EOL;
    }
}
