<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;

// ════════════════════════════════════════════════════════════
//  إنشاء حساب مدير النظام — أو إعادة ضبط كلمة مروره.
//
//  يلزم في حالتين: أول تهيئة لخادمٍ فارغ، وبعد `platform:reset` إن لم يُبقَ
//  حساب. وبدونه يكون الخيار الوحيد لاستعادة الدخول هو تحرير القاعدة يدوياً.
//
//  كلمة المرور تُولَّد ما لم تُملَ، وتُطبع مرّة واحدة، ويُلزَم تغييرها عند
//  أول دخول — فلا تبقى كلمةٌ يعرفها من نفّذ الأمر.
// ════════════════════════════════════════════════════════════
class CreateAdmin extends Command
{
    protected $signature = 'kafaat:create-admin
        {username=admin : اسم المستخدم}
        {--name= : الاسم الكامل}
        {--email= : البريد}
        {--password= : كلمة المرور (تُولَّد إن غابت)}
        {--role=ADMIN : رمز الدور}
        {--reset : أعد ضبط كلمة مرور حسابٍ قائم بدل الرفض}';

    protected $description = 'إنشاء حساب مدير النظام أو إعادة ضبط كلمة مروره';

    public function handle(): int
    {
        $username = (string) $this->argument('username');
        $roleCode = strtoupper((string) $this->option('role'));

        $role = Role::where('code', $roleCode)->first();
        if (!$role) {
            $this->error("الدور «{$roleCode}» غير موجود. الأدوار المتاحة: "
                . Role::pluck('code')->implode('، '));
            return self::FAILURE;
        }

        $existing = User::where('username', $username)->first();
        if ($existing && !$this->option('reset')) {
            $this->error("المستخدم «{$username}» موجود. استعمل --reset لإعادة ضبط كلمة مروره.");
            return self::FAILURE;
        }

        // كلمة مولَّدة: طولٌ كافٍ وأصنافٌ أربعة، ولا تُشتقّ من اسم أو تاريخ
        $password = (string) ($this->option('password') ?: $this->generatePassword());

        $attrs = [
            'full_name' => (string) ($this->option('name') ?: ($existing->full_name ?? 'مدير النظام')),
            'email' => (string) ($this->option('email') ?: ($existing->email ?? $username . '@kafaat.local')),
            'password' => $password,      // cast «hashed» يتولّى التجزئة
            'role_id' => $role->id,
            'is_active' => true,
            // إلزامي دائماً: من نفّذ الأمر يرى الكلمة، فيجب ألّا تبقى صالحة بعده
            'must_change_password' => true,
            'failed_attempts' => 0,
            'locked_until' => null,
        ];

        $user = User::updateOrCreate(['username' => $username], $attrs);

        $this->newLine();
        $this->info($existing ? '✓ أُعيد ضبط الحساب' : '✓ أُنشئ الحساب');
        $this->line('  اسم المستخدم : ' . $user->username);
        $this->line('  الدور        : ' . $role->name_ar . ' (' . $role->code . ')');
        if (!$this->option('password')) {
            $this->newLine();
            $this->line('  <options=bold>كلمة المرور (تُعرض مرّة واحدة):</> ' . $password);
        }
        $this->newLine();
        $this->warn('يُطلب تغيير كلمة المرور عند أول دخول.');

        return self::SUCCESS;
    }

    private function generatePassword(): string
    {
        // صنفٌ من كل نوع أولاً ثم حشوٌ عشوائي، والخلط أخيراً — التوليد العشوائي
        // الصرف قد يُخرج كلمةً تُرفض من قواعد التعقيد فيُعاد الأمر بلا سبب ظاهر.
        $sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789', '@#%&*+='];
        $out = '';
        foreach ($sets as $s) $out .= $s[random_int(0, strlen($s) - 1)];
        $all = implode('', $sets);
        for ($i = 0; $i < 12; $i++) $out .= $all[random_int(0, strlen($all) - 1)];

        // الخلط بـrandom_int لا بـstr_shuffle: الأخيرة تستعمل Mt19937 غير
        // المعمّى، فيصير ترتيب الحروف متوقّعاً وإن كان اختيارها عشوائياً آمناً.
        $chars = str_split($out);
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }
}
