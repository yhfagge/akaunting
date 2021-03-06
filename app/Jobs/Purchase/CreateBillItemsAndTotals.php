<?php

namespace App\Jobs\Purchase;

use App\Abstracts\Job;
use App\Models\Purchase\Bill;
use App\Models\Purchase\BillTotal;
use App\Traits\Currencies;
use App\Traits\DateTime;

class CreateBillItemsAndTotals extends Job
{
    use Currencies, DateTime;

    protected $bill;

    protected $request;

    /**
     * Create a new job instance.
     *
     * @param  $bill
     * @param  $request
     */
    public function __construct($bill, $request)
    {
        $this->bill = $bill;
        $this->request = $this->getRequestInstance($request);
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $precision = config('money.' . $this->bill->currency_code . '.precision');

        list($sub_total, $discount_amount_total, $taxes) = $this->createItems();

        $sort_order = 1;

        // Add sub total
        BillTotal::create([
            'company_id' => $this->bill->company_id,
            'bill_id' => $this->bill->id,
            'code' => 'sub_total',
            'name' => 'bills.sub_total',
            'amount' => round($sub_total, $precision),
            'sort_order' => $sort_order,
        ]);

        $this->request['amount'] += $sub_total;

        $sort_order++;

        // Add discount
        if ($discount_amount_total > 0) {
            BillTotal::create([
                'company_id' => $this->bill->company_id,
                'bill_id' => $this->bill->id,
                'code' => 'item_discount',
                'name' => 'bills.item_discount',
                'amount' => round($discount_amount_total, $precision),
                'sort_order' => $sort_order,
            ]);

            $this->request['amount'] -= $discount_amount_total;

            $sort_order++;
        }

        if (!empty($this->request['discount'])) {
            $discount_total = ($sub_total - $discount_amount_total) * ($this->request['discount'] / 100);

            BillTotal::create([
                'company_id' => $this->bill->company_id,
                'bill_id' => $this->bill->id,
                'code' => 'discount',
                'name' => 'bills.discount',
                'amount' => round($discount_total, $precision),
                'sort_order' => $sort_order,
            ]);

            $this->request['amount'] -= $discount_total;

            $sort_order++;
        }

        // Add taxes
        if (!empty($taxes)) {
            foreach ($taxes as $tax) {
                BillTotal::create([
                    'company_id' => $this->bill->company_id,
                    'bill_id' => $this->bill->id,
                    'code' => 'tax',
                    'name' => $tax['name'],
                    'amount' => round($tax['amount'], $precision),
                    'sort_order' => $sort_order,
                ]);

                $this->request['amount'] += $tax['amount'];

                $sort_order++;
            }
        }

        // Add extra totals, i.e. shipping fee
        if (!empty($this->request['totals'])) {
            foreach ($this->request['totals'] as $total) {
                $total['company_id'] = $this->bill->company_id;
                $total['bill_id'] = $this->bill->id;
                $total['sort_order'] = $sort_order;

                if (empty($total['code'])) {
                    $total['code'] = 'extra';
                }

                $total['amount'] = round($total['amount'], $precision);

                BillTotal::create($total);

                if (empty($total['operator']) || ($total['operator'] == 'addition')) {
                    $this->request['amount'] += $total['amount'];
                } else {
                    // subtraction
                    $this->request['amount'] -= $total['amount'];
                }

                $sort_order++;
            }
        }

        $this->request['amount'] = round($this->request['amount'], $precision);

        // Add total
        BillTotal::create([
            'company_id' => $this->bill->company_id,
            'bill_id' => $this->bill->id,
            'code' => 'total',
            'name' => 'bills.total',
            'amount' =>  $this->request['amount'],
            'sort_order' => $sort_order,
        ]);
    }

    protected function createItems()
    {
        $sub_total = $discount_amount = $discount_amount_total = 0;

        $taxes = [];

        if (empty($this->request['items'])) {
            return [$sub_total, $discount_amount_total, $taxes];
        }

        foreach ((array) $this->request['items'] as $item) {
            $item['global_discount'] = 0;

            if (!empty($this->request['discount'])) {
                $item['global_discount'] = $this->request['discount'];
            }

            $bill_item = $this->dispatch(new CreateBillItem($this->bill, $item));

            $item_amount = (double) $item['price'] * (double) $item['quantity'];

            $discount_amount = 0;

            if (!empty($item['discount'])) {
                $discount_amount = ($item_amount * ($item['discount'] / 100));
            }

            // Calculate totals
            $sub_total += $bill_item->total + $discount_amount;

            $discount_amount_total += $discount_amount;

            if (!$bill_item->item_taxes) {
                continue;
            }

            // Set taxes
            foreach ((array) $bill_item->item_taxes as $item_tax) {
                if (array_key_exists($item_tax['tax_id'], $taxes)) {
                    $taxes[$item_tax['tax_id']]['amount'] += $item_tax['amount'];
                } else {
                    $taxes[$item_tax['tax_id']] = [
                        'name' => $item_tax['name'],
                        'amount' => $item_tax['amount']
                    ];
                }
            }
        }

        return [$sub_total, $discount_amount_total, $taxes];
    }
}
