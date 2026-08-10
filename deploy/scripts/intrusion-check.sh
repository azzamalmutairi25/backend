#!/usr/bin/env bash
# ════════════════════════════════════════════════════════════
#  فحص أثر الاختراق — قراءة فقط، لا يغيّر شيئاً على الخادم.
#
#  يُشغَّل على خادم الإنتاج نفسه ويجيب سؤالاً واحداً: هل في الخادم أثرٌ
#  لدخولٍ أو تغييرٍ لم نفعله نحن؟ لا يعتمد على أداة خارجية ولا على شبكة —
#  يقرأ ما في النظام أصلاً.
#
#  الاستعمال على الخادم:
#      sudo bash intrusion-check.sh            # كل الفحوص
#      bash intrusion-check.sh                 # ما لا يحتاج صلاحية جذر
#
#  ما يستحقّ الوقوف عنده مطبوعٌ بعلامة [!] — وليس كلّ ما عليه علامة اختراقاً،
#  بل كلّ ما يحتاج تفسيراً.
# ════════════════════════════════════════════════════════════
set -uo pipefail

APP="${APP:-/home/tamkeenadmin/kafaat}"      # جذر النشر — عدّله إن اختلف
WEB_USER="${WEB_USER:-www-data}"
say() { printf '\n\033[1;32m══ %s\033[0m\n' "$1"; }
flag() { printf '\033[1;33m[!]\033[0m %s\n' "$1"; }
root() { [ "$(id -u)" -eq 0 ]; }

say "١) الدخول: من دخل، ومن حاول"
who
echo "── آخر عشرين دخولاً ──"
last -20 -F 2>/dev/null | head -22
if root; then
  echo "── محاولات فاشلة (آخر ٢٠) ──"
  lastb -20 -F 2>/dev/null | head -22 || echo "لا سجلّ btmp"
  echo "── عدد الفشل لكل عنوان ──"
  lastb 2>/dev/null | awk '{print $3}' | sort | uniq -c | sort -rn | head -10
else
  flag "شغّله بـsudo لقراءة محاولات الدخول الفاشلة"
fi

say "٢) الحسابات: من يملك الدخول"
echo "── حسابات بصلاحية جذر (يجب أن يكون root وحده) ──"
awk -F: '$3==0{print "  "$1}' /etc/passwd
echo "── حسابات بصدفة تفاعلية ──"
awk -F: '$7 !~ /(nologin|false|sync)$/ {print "  "$1"  uid="$3"  "$7}' /etc/passwd
echo "── أعضاء sudo ──"
getent group sudo 2>/dev/null; getent group wheel 2>/dev/null
echo "── تواريخ تعديل ملفات الحسابات ──"
ls -l --time-style=long-iso /etc/passwd /etc/shadow /etc/sudoers 2>/dev/null | awk '{print "  "$6" "$7"  "$NF}'
[ -d /etc/sudoers.d ] && { echo "── قواعد sudo إضافية ──"; ls -l --time-style=long-iso /etc/sudoers.d/ | tail -n +2; }

say "٣) مفاتيح SSH المصرَّح بها"
for f in /root/.ssh/authorized_keys /home/*/.ssh/authorized_keys; do
  [ -r "$f" ] || continue
  echo "── $f"
  awk '{print "   "$NF"   ("$1")"}' "$f"
  n=$(grep -c . "$f" 2>/dev/null || echo 0)
  echo "   المجموع: $n مفتاح"
done
echo "(كل مفتاح هنا يدخل الخادم بلا كلمة مرور — يجب أن تعرف صاحب كلّ واحد)"

say "٤) ما يستمع على الشبكة"
if root; then ss -tulpnH 2>/dev/null | awk '{print "  "$1"  "$5"  "$7}' | sort -u
else ss -tulnH 2>/dev/null | awk '{print "  "$1"  "$5}' | sort -u; fi
echo "(المتوقّع: 22 و80 و443 و5432 على 127.0.0.1 — أي منفذ آخر يُفسَّر)"

say "٥) المهامّ المجدولة"
for u in root tamkeenadmin "$WEB_USER"; do
  c=$(crontab -u "$u" -l 2>/dev/null | grep -v '^#' | grep -c . || true)
  [ "${c:-0}" -gt 0 ] && { echo "── crontab $u"; crontab -u "$u" -l 2>/dev/null | grep -v '^#' | grep . | sed 's/^/   /'; }
done
ls -l --time-style=long-iso /etc/cron.d/ 2>/dev/null | tail -n +2 | sed 's/^/   /'
echo "── مؤقّتات systemd ──"; systemctl list-timers --all --no-pager 2>/dev/null | head -12

say "٦) الخدمات التي تعمل"
systemctl list-units --type=service --state=running --no-pager 2>/dev/null | head -25
echo "── وحدات مضافة يدوياً (خارج الحزم) ──"
ls -l --time-style=long-iso /etc/systemd/system/*.service 2>/dev/null | sed 's/^/   /'

say "٧) سلامة ملفات التطبيق"
if [ -d "$APP" ]; then
  cur=$(readlink -f "$APP/current" 2>/dev/null || echo "$APP")
  echo "الإصدار العامل: $cur"
  echo "── ملفات تغيّرت في آخر ٧ أيام داخل الإصدار (المتوقّع: لا شيء بعد النشر) ──"
  find "$cur" -type f -mtime -7 -not -path '*/storage/*' -not -path '*/bootstrap/cache/*' \
       -not -path '*/node_modules/*' 2>/dev/null | head -30
  echo "── ملفات PHP في أماكن لا يجوز أن تحوي شيفرة (أثر webshell) ──"
  find "$cur/public" "$cur/storage" -name '*.php' 2>/dev/null | grep -v '/public/index.php' | head -20 \
    && flag "راجع ما ظهر أعلاه إن ظهر شيء"
  echo "── ملفات يملكها غير المستخدم المتوقّع ──"
  find "$cur" -maxdepth 2 ! -user tamkeenadmin ! -user "$WEB_USER" ! -user root 2>/dev/null | head -10
else
  flag "لم أجد $APP — مرّر APP=/المسار/الصحيح"
fi

say "٨) أماكن التنفيذ المؤقّتة"
find /tmp /var/tmp /dev/shm -maxdepth 2 -type f \( -perm -u+x -o -name '*.sh' -o -name '*.php' \) 2>/dev/null | head -15
echo "(الفارغ هو الجواب السليم)"

say "٩) سجلّ الوِب: ما الذي طُرق"
NGX=/var/log/nginx/access.log
if [ -r "$NGX" ]; then
  echo "── أكثر الردود ──"; awk '{print $9}' "$NGX" 2>/dev/null | sort | uniq -c | sort -rn | head -8
  echo "── مسارات مشبوهة (فحص آلي/محاولات) ──"
  grep -aiE '\.env|/\.git|wp-admin|wp-login|phpmyadmin|/vendor/|\.\./|shell|eval\(|base64_' "$NGX" 2>/dev/null | tail -12 \
    || echo "   لا شيء"
  echo "── أكثر العناوين طلباً ──"; awk '{print $1}' "$NGX" 2>/dev/null | sort | uniq -c | sort -rn | head -8
else
  flag "تعذّر قراءة $NGX (جرّب sudo)"
fi

say "١٠) سجلّ التطبيق"
LOG="$APP/current/storage/logs/laravel.log"
[ -r "$LOG" ] || LOG=$(ls -t "$APP"/*/storage/logs/laravel.log 2>/dev/null | head -1)
if [ -n "${LOG:-}" ] && [ -r "$LOG" ]; then
  echo "الحجم: $(du -h "$LOG" | cut -f1)"
  echo "── آخر الأخطاء ──"; grep -a "ERROR\|CRITICAL\|EMERGENCY" "$LOG" 2>/dev/null | tail -8
else
  echo "لا سجلّ تطبيق مقروء"
fi

say "الخلاصة"
cat <<'TXT'
راجع كل سطر عليه [!]، ثم أجب عن أربعة أسئلة:
  • هل كل حساب في القائمة تعرفه؟ وكل مفتاح SSH تعرف صاحبه؟
  • هل كل منفذ مفتوح له سبب؟
  • هل تغيّر ملفٌ داخل الإصدار العامل بعد تاريخ نشره؟ (يجب ألّا يتغيّر)
  • هل في سجلّ الوِب طَرْقٌ ناجح (200) على مسارٍ لا وجود له في المنصّة؟
الفحص هذا يكشف الأثر الظاهر. الأثر المخفيّ (rootkit) يحتاج مقارنة بنسخة نظيفة.
TXT
