<?php

namespace App\Http\Requests\Vehicle\Concerns;

// للتذكير: منطق تطبيع والتحقق من حقول المركبة النصية، مشترك بين طلبي الإنشاء والتعديل.

trait NormalizesVehicleFields
{
    /**
     * يُستدعى من prepareForValidation(): يقصّ المسافات الطرفية ويدمج المسافات المتكررة
     * لكل حقل نصي مُرسَل فعلياً، دون التأثير على الحقول غير المُرسَلة إطلاقاً.
     */
    protected function normalizeVehicleTextFields(array $fields): void
    {
        $normalized = [];

        foreach ($fields as $field) {
            if ($this->has($field) && is_string($this->input($field))) {
                $value = trim($this->input($field));
                $value = preg_replace('/\s+/u', ' ', $value);
                $normalized[$field] = $value;
            }
        }

        if ($normalized) {
            $this->merge($normalized);
        }
    }

    /**
     * يسمح بحروف Unicode (عربية/إنجليزية) وأرقام ومسافات وشرطة وapostrophe وE&،
     * لحقول مثل الماركة والطراز.
     */
    protected function brandModelFormatRule(): \Closure
    {
        return function (string $attribute, $value, \Closure $fail) {
            if (!is_string($value)) {
                return;
            }
            if (!preg_match("/^[\p{L}\p{N}\s\-'&]+$/u", $value)) {
                $fail('القيمة تحتوي على رموز غير مسموحة.');
            }
        };
    }

    /**
     * يسمح فقط بحروف Unicode وأرقام ومسافة وشرطة لرقم اللوحة، ويتحقق من الطول
     * المعتبر (4 إلى 9 محارف) بعد تجاهل المسافات والشرطات.
     */
    protected function plateNumberFormatRule(): \Closure
    {
        return function (string $attribute, $value, \Closure $fail) {
            if (!is_string($value)) {
                return;
            }

            if (!preg_match('/^[\p{L}\p{N}\s\-]+$/u', $value)) {
                $fail('رقم اللوحة يحتوي على رموز غير مسموحة.');
                return;
            }

            $meaningfulLength = mb_strlen(preg_replace('/[\s\-]+/u', '', $value));

            if ($meaningfulLength < 4) {
                $fail('رقم اللوحة قصير جداً.');
            } elseif ($meaningfulLength > 9) {
                $fail('رقم اللوحة طويل جداً.');
            }
        };
    }
}
