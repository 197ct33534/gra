<?php

// Thông báo lỗi nhập liệu tiếng Việt cho các rule hay dùng; rule khác rơi về bản tiếng Anh.
return [

    'accepted' => ':attribute phải được chấp nhận.',
    'after' => ':attribute phải sau :date.',
    'after_or_equal' => ':attribute phải từ :date trở đi.',
    'array' => ':attribute phải là danh sách.',
    'before' => ':attribute phải trước :date.',
    'before_or_equal' => ':attribute phải từ :date trở về trước.',
    'boolean' => ':attribute chỉ nhận Có hoặc Không.',
    'confirmed' => ':attribute xác nhận không khớp.',
    'date' => ':attribute không phải ngày hợp lệ.',
    'decimal' => ':attribute phải có :decimal chữ số thập phân.',
    'different' => ':attribute và :other phải khác nhau.',
    'digits' => ':attribute phải gồm :digits chữ số.',
    'digits_between' => ':attribute phải gồm từ :min đến :max chữ số.',
    'email' => ':attribute phải là email hợp lệ.',
    'exists' => ':attribute đã chọn không tồn tại.',
    'gt' => [
        'numeric' => ':attribute phải lớn hơn :value.',
    ],
    'gte' => [
        'numeric' => ':attribute phải lớn hơn hoặc bằng :value.',
    ],
    'image' => ':attribute phải là ảnh.',
    'in' => ':attribute đã chọn không hợp lệ.',
    'integer' => ':attribute phải là số nguyên.',
    'lt' => [
        'numeric' => ':attribute phải nhỏ hơn :value.',
    ],
    'lte' => [
        'numeric' => ':attribute phải nhỏ hơn hoặc bằng :value.',
    ],
    'max' => [
        'array' => ':attribute không được quá :max mục.',
        'file' => ':attribute không được lớn hơn :max KB.',
        'numeric' => ':attribute không được lớn hơn :max.',
        'string' => ':attribute không được dài quá :max ký tự.',
    ],
    'mimes' => ':attribute phải là tệp dạng: :values.',
    'min' => [
        'array' => ':attribute phải có ít nhất :min mục.',
        'file' => ':attribute phải từ :min KB trở lên.',
        'numeric' => ':attribute phải lớn hơn hoặc bằng :min.',
        'string' => ':attribute phải có ít nhất :min ký tự.',
    ],
    'numeric' => ':attribute phải là số.',
    'regex' => ':attribute không đúng định dạng.',
    'required' => 'Vui lòng nhập :attribute.',
    'required_if' => 'Vui lòng nhập :attribute khi :other là :value.',
    'same' => ':attribute và :other phải giống nhau.',
    'string' => ':attribute phải là chuỗi ký tự.',
    'unique' => ':attribute đã tồn tại trong hệ thống.',

];
