<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $products = Product::with('supplier')->withSum('batchStocks as stock', 'remain_qty')->get();

        // Optimized: Single pre-aggregated query for all pending quantities instead of N+1 queries per product
        $pendingStocks = \App\Models\LoadListItem::query()
            ->join('loadings', 'load_list_items.loading_id', '=', 'loadings.id')
            ->join('batch__stocks', 'load_list_items.batch_id', '=', 'batch__stocks.id')
            ->where('loadings.status', 'pending')
            ->groupBy('batch__stocks.product_id')
            ->selectRaw('batch__stocks.product_id, SUM(load_list_items.qty) as pending_qty')
            ->pluck('pending_qty', 'product_id');

        foreach ($products as $product) {
            $shelfStock = (int) ($product->stock ?? 0);
            $product->shelf_stock = $shelfStock;
            $product->pending_stock = (int) ($pendingStocks[$product->id] ?? 0);
            $product->total_units = $shelfStock;
        }

        return response()->json($products);
    }

    /**
     * Search products by barcode, material_code, or name for POS.
     */
    public function search(Request $request)
    {
        $query = $request->input('query');

        if (!$query) {
            return response()->json([]);
        }

        $products = Product::where('barcode', $query)
            ->orWhere('material_code', $query)
            ->orWhere('name', 'LIKE', "%{$query}%")
            ->with(['supplier', 'batchStocks' => function ($q) {
                $q->where('remain_qty', '>', 0)->with('supplierInvoice');
            }])
            ->get();

        foreach ($products as $product) {
            $product->total_available_stock = (int) $product->batchStocks->sum('remain_qty');
        }

        return response()->json($products);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'material_code' => 'required|string|unique:products,material_code|max:255',
            'barcode' => 'required|string|unique:products,barcode|max:255',
            'name' => 'required|string|max:255',
            'supplier_id' => 'required|exists:suppliers,id',
        ]);

        $product = Product::create($validated);

        return response()->json($product, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $product = Product::withSum('batchStocks as stock', 'remain_qty')->findOrFail($id);

        $pendingQty = \App\Models\LoadListItem::query()
            ->join('loadings', 'load_list_items.loading_id', '=', 'loadings.id')
            ->join('batch__stocks', 'load_list_items.batch_id', '=', 'batch__stocks.id')
            ->where('loadings.status', 'pending')
            ->where('batch__stocks.product_id', $product->id)
            ->sum('load_list_items.qty');

        $shelfStock = (int) ($product->stock ?? 0);
        $product->shelf_stock = $shelfStock;
        $product->pending_stock = (int) $pendingQty;
        $product->total_units = $shelfStock;

        return response()->json($product);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $product = Product::findOrFail($id);

        $validated = $request->validate([
            'material_code' => [
                'required',
                'string',
                'max:255',
                Rule::unique('products')->ignore($product->id),
            ],
            'barcode' => [
                'required',
                'string',
                'max:255',
                Rule::unique('products')->ignore($product->id),
            ],
            'name' => 'required|string|max:255',
            'supplier_id' => 'required|exists:suppliers,id',
        ]);

        $product->update($validated);

        return response()->json($product);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $product = Product::findOrFail($id);
        $product->delete();

        return response()->json(null, 204);
    }
}
