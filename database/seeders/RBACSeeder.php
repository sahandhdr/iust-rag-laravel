<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use App\Models\Department\Department;
use App\Models\Permission\Permission;
use App\Models\Permission\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class RBACSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $permissions = $this->seedPermissions();
            $roles = $this->seedRoles();
            $departments = $this->seedDepartments();

            $this->assertDepartmentFkTarget();

            $this->attachRolePermissions($roles, $permissions);
            $this->seedUsers($roles, $departments);
        });
    }

    /**
     * Fail-fast اگر FK هنوز به جدول اشتباه اشاره کند.
     */
    private function assertDepartmentFkTarget(): void
    {
        $row = DB::selectOne("
            SELECT REFERENCED_TABLE_NAME AS ref_table
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'department_user'
              AND COLUMN_NAME = 'dept_id'
              AND REFERENCED_TABLE_NAME IS NOT NULL
            LIMIT 1
        ");

        if ($row && $row->ref_table !== 'departments') {
            throw new RuntimeException(
                "FK department_user.dept_id points to '{$row->ref_table}' instead of 'departments'. Fix schema first."
            );
        }
    }

    private function seedPermissions(): array
    {
        $items = [
            ['title_en' => 'documents.read.public', 'title_fa' => 'خواندن اسناد عمومی'],
            ['title_en' => 'documents.read.department', 'title_fa' => 'خواندن اسناد دپارتمان'],
            ['title_en' => 'documents.read.all', 'title_fa' => 'خواندن همه اسناد'],
            ['title_en' => 'rbac.bypass', 'title_fa' => 'عبور از محدودیت RBAC'],
            ['title_en' => 'all', 'title_fa' => 'دسترسی کامل'],
        ];

        $map = [];
        foreach ($items as $item) {
            $map[$item['title_en']] = Permission::query()->updateOrCreate(
                ['title_en' => $item['title_en']],
                ['title_fa' => $item['title_fa']]
            );
        }
        return $map;
    }

    private function seedRoles(): array
    {
        $items = [
            ['title_en' => 'public', 'title_fa' => 'عمومی / ارباب رجوع'],
            ['title_en' => 'staff', 'title_fa' => 'کارشناس'],
            ['title_en' => 'supervisor', 'title_fa' => 'سرپرست'],
            ['title_en' => 'manager', 'title_fa' => 'مدیر'],
            ['title_en' => 'admin', 'title_fa' => 'ادمین'],
            ['title_en' => 'developer', 'title_fa' => 'توسعه‌دهنده'],
        ];

        $map = [];
        foreach ($items as $item) {
            $map[$item['title_en']] = Role::query()->updateOrCreate(
                ['title_en' => $item['title_en']],
                ['title_fa' => $item['title_fa']]
            );
        }
        return $map;
    }

    private function seedDepartments(): array
    {
        $items = [
            ['title_en' => 'it', 'title_fa' => 'فناوری اطلاعات'],
            ['title_en' => 'hr', 'title_fa' => 'منابع انسانی'],
            ['title_en' => 'ce_dept', 'title_fa' => 'مرکز کامپیوتر'],
        ];

        $map = [];
        foreach ($items as $item) {
            $dept = Department::query()->updateOrCreate(
                ['title_en' => $item['title_en']],
                ['title_fa' => $item['title_fa']]
            );

            // اطمینان: رکورد واقعاً در departments است
            $exists = DB::table('departments')->where('id', $dept->id)->exists();
            if (!$exists) {
                throw new RuntimeException(
                    "Department id={$dept->id} not found in table departments after insert."
                );
            }

            $map[$item['title_en']] = $dept;
        }
        return $map;
    }

    private function attachRolePermissions(array $roles, array $permissions): void
    {
        $roles['public']->permissions()->sync([
            $permissions['documents.read.public']->id,
        ]);

        $roles['staff']->permissions()->sync([
            $permissions['documents.read.public']->id,
            $permissions['documents.read.department']->id,
        ]);

        $roles['supervisor']->permissions()->sync([
            $permissions['documents.read.public']->id,
            $permissions['documents.read.department']->id,
        ]);

        $roles['manager']->permissions()->sync([
            $permissions['documents.read.public']->id,
            $permissions['documents.read.department']->id,
            $permissions['documents.read.all']->id,
        ]);

        $roles['admin']->permissions()->sync([
            $permissions['documents.read.public']->id,
            $permissions['documents.read.department']->id,
            $permissions['documents.read.all']->id,
            $permissions['rbac.bypass']->id,
            $permissions['all']->id,
        ]);

        $roles['developer']->permissions()->sync([
            $permissions['documents.read.all']->id,
            $permissions['rbac.bypass']->id,
            $permissions['all']->id,
        ]);
    }

    private function seedUsers(array $roles, array $departments): void
    {
        $publicUser = User::query()->updateOrCreate(
            ['email' => 'public@iust.local'],
            [
                'name' => 'Public',
                'surname' => 'User',
                'username' => 'public_user',
                'phone' => null,
                'ncode' => null,
                'password' => Hash::make('password'),
                'bio' => 'Phase 1 public test user',
            ]
        );
        $publicUser->roles()->sync([$roles['public']->id]);

        $staffUser = User::query()->updateOrCreate(
            ['email' => 'staff@iust.local'],
            [
                'name' => 'Staff',
                'surname' => 'User',
                'username' => 'staff_user',
                'phone' => null,
                'ncode' => null,
                'password' => Hash::make('password'),
                'bio' => 'Phase 2 staff test user',
            ]
        );
        $staffUser->roles()->sync([$roles['staff']->id]);
        $staffUser->departments()->sync([$departments['it']->id]);

        $adminUser = User::query()->updateOrCreate(
            ['email' => 'admin@iust.local'],
            [
                'name' => 'Admin',
                'surname' => 'User',
                'username' => 'admin_user',
                'phone' => null,
                'ncode' => null,
                'password' => Hash::make('password'),
                'bio' => 'Admin test user with bypass',
            ]
        );
        $adminUser->roles()->sync([$roles['admin']->id]);
        $adminUser->departments()->sync([
            $departments['it']->id,
            $departments['hr']->id,
            $departments['ce_dept']->id,
        ]);
    }
}
