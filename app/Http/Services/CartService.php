<?php


namespace App\Http\Services;


use App\Jobs\SendMail;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;

class CartService
{
    public function create($request)
    {
        $qty = (int)$request->input('num_product');
        $product_id = (int)$request->input('product_id');

        if ($qty <= 0 || $product_id <= 0) {
            Session::flash('error', 'Số lượng hoặc Sản phẩm không chính xác');
            return false;
        }

        $carts = Session::get('carts');
        if (is_null($carts)) {
            Session::put('carts', [
                $product_id => $qty
            ]);
            return true;
        }

        $exists = Arr::exists($carts, $product_id);
        if ($exists) {
            $carts[$product_id] = $carts[$product_id] + $qty;
            Session::put('carts', $carts);
            return true;
        }

        $carts[$product_id] = $qty;
        Session::put('carts', $carts);

        return true;
    }

    public function getProduct()
    {
        $carts = Session::get('carts');
        if (is_null($carts)) return [];

        $productId = array_keys($carts);
        return Product::select('id', 'name', 'price', 'price_sale', 'thumb')
            ->where('active', 1)
            ->whereIn('id', $productId)
            ->get();
    }

    public function update($request)
    {
        Session::put('carts', $request->input('num_product'));

        return true;
    }

    public function remove($id)
    {
        $carts = Session::get('carts');
        unset($carts[$id]);

        Session::put('carts', $carts);
        return true;
    }

    public function addCart($request)
    {
        try {
            DB::beginTransaction();

            $carts = Session::get('carts');

            if (is_null($carts)){
                // return false;

            // chat
            Session::flash('error', 'Giỏ hàng trống. Vui lòng thêm sản phẩm vào giỏ.');
            return false;
            // {throw new \Exception('Giỏ hàng trống');}
            }
            $customer = Customer::create([
                'name' => $request->input('name'),
                'phone' => $request->input('phone'),
                'address' => $request->input('address'),
                'email' => $request->input('email'),
                'content' => $request->input('content')
            ]);

            $this->infoProductCart($carts, $customer->id);

            DB::commit();
            Session::flash('success', 'Đặt Hàng Thành Công');
            // Session::flush();
            #Queue
            SendMail::dispatch($request->input('email'))->delay(now()->addSeconds(2));

            Session::forget('carts');

            // Session::flash('success', 'Đặt hàng thành công');
        }
        catch (\Exception $err) {
            DB::rollBack();
            // Session::forget('success'); // Xóa thông báo thành công nếu có
            Session::flash('error', 'Đặt hàng không thành công, vui lòng thử lại');
            return false;
        }

        return true;
    }

    // public function addCart($request)
    // {
    //     // Khởi tạo biến trạng thái để theo dõi quá trình đặt hàng
    //     $orderPlacedSuccessfully = false;
    
    //     try {
    //         // Bắt đầu giao dịch
    //         DB::beginTransaction();
    
    //         // Lấy thông tin giỏ hàng từ session
    //         $carts = Session::get('carts');
    
    //         // Kiểm tra xem giỏ hàng có trống không
    //         if (is_null($carts)) {
    //             Session::flash('error', 'Giỏ hàng trống. Vui lòng thêm sản phẩm vào giỏ.');
    //             return false;
    //         }
    
    //         // Tạo khách hàng mới
    //         $customer = Customer::create([
    //             'name' => $request->input('name'),
    //             'phone' => $request->input('phone'),
    //             'address' => $request->input('address'),
    //             'email' => $request->input('email'),
    //             'content' => $request->input('content')
    //         ]);
    
    //         // Thêm thông tin sản phẩm vào giỏ hàng
    //         $this->infoProductCart($carts, $customer->id);
    
    //         // Hoàn tất giao dịch
    //         DB::commit();
    
    //         // Đặt trạng thái đặt hàng thành công
    //         $orderPlacedSuccessfully = true;
    
    //         // Xóa giỏ hàng khỏi session
    //         Session::forget('carts');
    //     } catch (\Exception $err) {
    //         // Hủy giao dịch nếu có lỗi
    //         DB::rollBack();
    //         Session::flash('error', 'Đặt Hàng Lỗi, Vui lòng thử lại sau');
    //         return false;
    //     }
    
    //     // Đặt thông báo thành công nếu không có lỗi
    //     if ($orderPlacedSuccessfully) {
    //         Session::flash('success', 'Đặt Hàng Thành Công');
    
    //         // Gửi email bằng hàng đợi (Queue)
    //         SendMail::dispatch($request->input('email'))->delay(now()->addSeconds(2));
    //     }
    
    //     return true;
    // }
    


    protected function infoProductCart($carts, $customer_id)
    {
        $productId = array_keys($carts);
        $products = Product::select('id', 'name', 'price', 'price_sale', 'thumb')
            ->where('active', 1)
            ->whereIn('id', $productId)
            ->get();

        $data = [];
        foreach ($products as $product) {
            $data[] = [
                'customer_id' => $customer_id,
                'product_id' => $product->id,
                'pty'   => $carts[$product->id],
                'price' => $product->price_sale != 0 ? $product->price_sale : $product->price
            ];
        }

        return Cart::insert($data);
    }

    public function getCustomer()
    {
        return Customer::orderByDesc('id')->paginate(15);
    }

    public function getProductForCart($customer)
    {
        return $customer->carts()->with(['product' => function ($query) {
            $query->select('id', 'name', 'thumb');
        }])->get();
    }
}
