<?php

namespace Database\Seeders;

use App\Models\Employee;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * One sign-in-ready employee per role (spec Section 15's 9 roles, plus
 * Store Orders) so every
 * permission set in PermissionSeeder actually has a login to exercise it
 * with. All local dev only — password is 'password' for every account,
 * hashed via the model's casts().
 */
class EmployeeSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $superAdmin = $this->employee('Super Admin', 'admin@waqar.test', '+201000000000');
        $superAdmin->assignRole('Super Admin');

        $chairman = $this->employee('Chairman', 'chairman@waqar.test', '+201000000001');
        $chairman->assignRole('Chairman');

        $viceChairman = $this->employee('Vice Chairman', 'vicechairman@waqar.test', '+201000000002');
        $viceChairman->assignRole('Vice Chairman');

        $warehouseManager = $this->employee('Warehouse Manager', 'warehouse@waqar.test', '+201000000003');
        $warehouseManager->assignRole('Warehouse Manager');

        // Team Leader created before the agent reporting to them, so
        // team_leader_id has somewhere to point (Section 15, Question 16).
        $teamLeader = $this->employee('CS Team Leader', 'cs.leader@waqar.test', '+201000000004');
        $teamLeader->assignRole('Customer Service Team Leader');

        $csAgent = $this->employee('CS Agent', 'cs.agent@waqar.test', '+201000000005', $teamLeader->id);
        $csAgent->assignRole('Customer Service');

        $storeOrders = $this->employee('Store Orders Clerk', 'store.orders@waqar.test', '+201000000009');
        $storeOrders->assignRole('Store Orders');

        $checking = $this->employee('Checking Clerk', 'checking@waqar.test', '+201000000006');
        $checking->assignRole('Checking');

        $deliveryManager = $this->employee('Delivery Manager', 'delivery@waqar.test', '+201000000007');
        $deliveryManager->assignRole('Delivery Manager');

        $accountant = $this->employee('Accountant', 'accounting@waqar.test', '+201000000008');
        $accountant->assignRole('Accounting');
    }

    private function employee(string $name, string $email, string $phone, ?int $teamLeaderId = null): Employee
    {
        return Employee::updateOrCreate(
            ['email' => $email],
            [
                'full_name' => $name,
                'phone' => $phone,
                'password' => 'password',
                'residence_address' => 'Cairo, Egypt',
                'national_id_number' => '29001010100000',
                'is_active' => true,
                'team_leader_id' => $teamLeaderId,
            ],
        );
    }
}
