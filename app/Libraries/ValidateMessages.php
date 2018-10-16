<?php namespace App\Libraries;


trait ValidateMessages {

    public function messages()
    {
        $messages = [
            'mobile.required' => '{"code":"10003", "message":"mobile required"}',
            'mobile.digits' => '{"code":"10004", "message":"mobile not valid"}',
            'mobile.unique' => '{"code":"10001", "message":"mobile exists"}',

            'verify_code.required' => '{"code":"10040", "message":"verify code required"}',
            'verify_code.digits' => '{"code":"100041", "message":"verify code not valid"}',
            // 'password.required' => '{"code":"10012", "message":"password too simple"}',
            // 'password.required' => '{"code":"10013", "message":"password not valid"}',
            'operation.required' => '{"code":"10045", "message":"operation required"}',
            'operation.in' => '{"code":"10043", "message":"operation not valid"}',

            'password.required' => '{"code":"10011", "message":"password required"}',
            'old.required' => '{"code":"10011", "message":"old password required"}',
            'new.required' => '{"code":"10011", "message":"new password required"}',
            // 'password.required' => '{"code":"10012", "message":"password too simple"}',
            // 'password.required' => '{"code":"10013", "message":"password not valid"}',

            'did.required' => '{"code":"20032", "message":"did required"}',
            'did.regex' => '{"code":"20033", "message":"did not valid"}',

            'prompt.required' => '{"code":"20040", "message":"parameter prompt required"}',
            'prompt.in' => '{"code":"20041", "message":"paramter prompt value not valid"}',

            'offset.required' => '{"code":"20040", "message":"parameter :attribute required"}',
            'offset.integer' => '{"code":"20041", "message":"value of :attribute not valid"}',

            'limit.required' => '{"code":"20040", "message":"parameter :attribute required"}',
            'limit.integer' => '{"code":"20041", "message":"value of :attribute not valid"}',

            'period.required' => '{"code":"20040", "message":"parameter :attribute required"}',
            'period.in' => '{"code":"20041", "message":"value of :attribute not valid"}',
            'period.numeric' => '{"code":"20041", "message":"value of :attribute not valid"}',
            'period.min' => '{"code":"20041", "message":"value of :attribute not valid"}',

            'product_id.required' => '{"code":"20040", "message":"parameter :attribute required"}',
            'product_id.digits' => '{"code":"20041", "message":"value of :attribute not valid"}',
            'product_id.digits_between' => '{"code":"20041", "message":"value of :attribute not valid"}',

            'product_type.required' => '{"code":"20040", "message":"parameter :attribute required"}',
            'product_type.in' => '{"code":"20041", "message":"value of :attribute not valid"}',

            'trade_amount.required' => '{"code":"20040", "message":"parameter :attribute required"}',
            'trade_amount.numeric' => '{"code":"20041", "message":"value of :attribute not valid"}',
            'trade_amount.min' => '{"code":"20041", "message":"parameter :attribute not valid"}',

            'trade_date.required' => '{"code":"20040", "message":"parameter :attribute required"}',
            'trade_date.date' => '{"code":"20041", "message":"value of :attribute not valid"}',
            'trade_date.after' => '{"code":"20041", "message":"value of :attribute not valid"}',

            'interest_start_date.required' => '{"code":"20040", "message":"parameter :attribute required"}',
            'interest_start_date.date' => '{"code":"20041", "message":"value of :attribute not valid"}',
            'interest_start_date.after' => '{"code":"20041", "message":"value of :attribute not valid"}',

            'trade_time.required' => '{"code":"20040", "message":"parameter :attribute required"}',
            'trade_time.date' => '{"code":"20041", "message":"value of :attribute not valid"}',
            'trade_time.after' => '{"code":"20041", "message":"value of :attribute not valid"}',

            'redemption_date.required' => '{"code":"20040", "message":"parameter :attribute required"}',
            'redemption_date.date' => '{"code":"20041", "message":"value of :attribute not valid"}',
            'redemption_date.after' => '{"code":"20041", "message":"value of :attribute not valid"}',

            'redemption_amount.required' => '{"code":"20040", "message":"parameter :attribute required"}',
            'redemption_amount.numeric' => '{"code":"20041", "message":"value of :attribute not valid"}',
            'redemption_amount.min' => '{"code":"20041", "message":"parameter :attribute not valid"}',

            'redemption_share.required' => '{"code":"20040", "message":"parameter :attribute required"}',
            'redemption_share.numeric' => '{"code":"20041", "message":"value of :attribute not valid"}',
            'redemption_share.min' => '{"code":"20041", "message":"parameter :attribute not valid"}',

            'before3pm.required' => '{"code":"20040", "message":"parameter :attribute required"}',
            'before3pm.boolean' => '{"code":"20041", "message":"value of :attribute not valid"}',

            'trade_id.required' => '{"code":"20040", "message":"parameter :attribute required"}',
            'trade_id.min' => '{"code":"20041", "message":"value of :attribute not valid"}',
            'trade_id.numeric' => '{"code":"20041", "message":"value of :attribute not valid"}',

            'bonus_type.required' => '{"code":"20040", "message":"parameter :attribute required"}',
            'bonus_type.in' => '{"code":"20041", "message":"value of :attribute not valid"}',

            'buying_fee.required' => '{"code":"20040", "message":"parameter :attribute required"}',
            'buying_fee.numeric' => '{"code":"20041", "message":"value of :attribute not valid"}',

            'repay_method_code.required' => '{"code":"20040", "message":"parameter :attribute required"}',
            'repay_method_code.in' => '{"code":"20041", "message":"value of :attribute not valid"}',

            'name.required' => '{"code":"20040", "message":"parameter :attribute required"}',

            'platform_id.required' => '{"code":"20040", "message":"parameter :attribute required"}',
            'platform_id.digits_between' => '{"code":"20041", "message":"value of :attribute not valid"}',

            'platform_name.required' => '{"code":"20040", "message":"parameter :attribute required"}',

            'yearly_yield.required' => '{"code":"20040", "message":"parameter :attribute required"}',
            'yearly_yield.numeric' => '{"code":"20041", "message":"value of :attribute not valid"}',

            'mofang.required' => '{"code":"20040", "message":"parameter :attribute required"}',
            'mofang.boolean' => '{"code":"20041", "message":"value of :attribute not valid"}',

            'method.required' => '{"code":"20040", "message":"parameter :attribute required"}',
            'method.string' => '{"code":"20041", "message":"value of :attribute not valid"}',
            'method.in' => '{"code":"20041", "message":"parameter :attribute not valid"}',

            'type.required' => '{"code":"20040", "message":"parameter :attribute required"}',
            'type.numeric' => '{"code":"20041", "message":"value of :attribute not valid"}',
            'type.in' => '{"code":"20041", "message":"parameter :attribute not valid"}',

            'new_amount.required' => '{"code":"20040", "message":"parameter :attribute required"}',
            'new_amount.numeric' => '{"code":"20041", "message":"value of :attribute not valid"}',

            'fund_id.required' => '{"code":"20040", "message":"缺少必要参数 :attribute"}',
            'fund_id.digits' => '{"code":"20041", "message":"参数 :attribute 值不合法"}',

            'company_id.required' => '{"code":"20040", "message":"缺少必要参数 :attribute"}',
            'company_id.digits' => '{"code":"20041", "message":"参数 :attribute 值不合法"}',

            'page.required' => '{"code":"20040", "message":"缺少必要参数 :attribute"}',
            'page.integer' => '{"code":"20041", "message":"参数 :attribute 值不合法"}',
            'page.min' => '{"code":"20041", "message":"参数 :attribute 值不合法"}',

            'portfolio_id.required' => '{"code":"20040", "message":"缺少必要参数 :attribute"}',
            'portfolio_id.integer' => '{"code":"20041", "message":"参数 :attribute 值不合法"}',

            'fixed_id.required' => '{"code":"20040", "message":"缺少必要参数 :attribute"}',
            'fixed_id.digits' => '{"code":"20041", "message":"参数 :attribute 值不合法"}',
            'fixed_id.numeric' => '{"code":"20041", "message":"参数 :attribute 值不合法"}',

            'reserve_bid_id.required' => '{"code":"20040", "message":"请选择预约产品"}',
            'reserve_bid_id.numeric' => '{"code":"20041", "message":"请选择预约产品"}',

            'reserve_bid_amount.required' => '{"code":"20040", "message":"请输入预约金额"}',
            'reserve_bid_amount.numeric' => '{"code":"20041", "message":"预约金额必须是整数"}',
            'reserve_bid_amount.between' => '{"code":"20041", "message":"预约金额在100元~100万元之间"}',

            'name.required' => '{"code":"20041", "message":"缺少name参数"}',
            'id_no.required' => '{"code":"20041", "message":"缺少id_no参数"}',
            'card_no.required' => '{"code":"20041", "message":"缺少card_no参数"}',

        ];

        return $messages;
    }

}
