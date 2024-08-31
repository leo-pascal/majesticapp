<?php

namespace App\Filament\Resources;

use Log;
use Filament\Forms;
use App\Models\Item;
use App\Models\User;
use Filament\Tables;
use App\Models\Order;
use App\Models\Price;
use Filament\Forms\Get;
use Filament\Forms\Set;
use App\Models\Customer;
use Filament\Forms\Form;
use App\Models\OrderItem;
use Filament\Tables\Table;
use Illuminate\Support\Number;
use App\Helpers\AllCalculations;
use Filament\Resources\Resource;
use Illuminate\Support\Facades\DB;
use Filament\Forms\Components\Card;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\Layout\Panel;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\SelectColumn;
use Illuminate\Database\Eloquent\Builder;
use Filament\Forms\Components\NumberInput;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\ToggleButtons;
use App\Filament\Resources\OrderResource\Pages;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Filament\Resources\OrderResource\RelationManagers;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
        ->schema([
            Card::make()
            ->schema([
                Grid::make(2)->schema([
                    Select::make('customer_id')
                    ->label('Customer')
                    ->options(Customer::all()->pluck('name', 'id'))
                    ->required()
                    ->reactive()
                    ->afterStateUpdated(function (Set $set, $state) {
                        // Preserve one item in the repeater
                        $set('items', [['item_id' => null, 'quantity' => 1, 'unit_price' => 0, 'vat' => 0, 'total_price' => 0]]);
                        // Update customer name in summary
                        $customer = Customer::find($state);
                        $set('customer_name', $customer ? $customer->name : 'N/A');
                    }),

                    Select::make('user_id')
                        ->label('Sales Staff')
                        ->options(User::all()->pluck('name', 'id'))
                        ->required(),

                    Textarea::make('remarks')
                    ->label('Remarks')
                    ->rows(3),

                    ToggleButtons::make('status')
                    ->inline()
                    ->label('Order Status')
                    ->options([
                        'new' => 'New',
                        'processing' => 'Processing',
                        'delivered' => 'Delivered',
                        'cancelled' => 'Cancelled',
                    ])
                    ->colors([
                        'new' => 'info',
                        'processing' => 'warning',
                        'delivered' => 'success',
                        'cancelled' => 'danger',
                    ])
                    ->icons([
                        'new' => 'heroicon-m-sparkles',
                        'processing' => 'heroicon-m-arrow-path',
                        'delivered' => 'heroicon-m-check-badge',
                        'cancelled' => 'heroicon-m-x-circle',
                    ])
                    ->default('new')
                    ->required(),
                ])->columns(2),

                Section::make('Order Items')
                ->schema([
                    Repeater::make('items')
                    ->relationship()
                    ->label('Order Items')
                    ->schema([
                        Select::make('item_id')
                        ->label('Item')
                        ->options(function (callable $get) {
                            $customerId = $get('../../customer_id');
                            if (!$customerId) return [];
                            return Price::where('customer_id', $customerId)
                                ->with('item')
                                ->get()
                                ->pluck('item.name', 'item_id');
                        })->columnSpan(3)
                        ->required()
                        ->reactive()
                        ->afterStateUpdated(function ($state, callable $get, Set $set) {
                            $customerId = $get('../../customer_id');
                            $price = Price::where('customer_id', $customerId)
                                ->where('item_id', $state)
                                ->value('price');
                            $set('unit_price', $price ?? 0);
                            $set('quantity', 1);
                            $set('vat', 0);
                            $set('total_price', 0);
                        })
                        ->disableOptionsWhenSelectedInSiblingRepeaterItems(),

                        TextInput::make('quantity')
                        ->label('Quantity')
                        ->numeric()
                        ->default(1)
                        ->minValue(1)
                        ->required()
                        ->reactive()->columnSpan(1)
                        ->afterStateUpdated(fn ($state, callable $get, Set $set) => self::calculateTotals($state, $get, $set)),

                        TextInput::make('unit_price')
                        ->label('Unit Price')
                        ->numeric()
                        ->step(0.01)
                        ->disabled()->columnSpan(2)
                        ->required()->dehydrated(),
                    
                        TextInput::make('vat')
                            ->label('Item VAT')
                            ->numeric()
                            ->step(0.01)->columnSpan(2)
                            ->disabled()
                            ->dehydrated()
                            ->afterStateHydrated(function ($state, $get, Set $set) {
                                $quantity = $get('quantity');
                                $unitPrice = $get('unit_price');
                                $vat = $quantity * $unitPrice * 0.05; // Assuming 5% VAT
                                $set('vat', round($vat, 2));
                            }),
                        
                        TextInput::make('total_price')
                        ->label('Item Total Amount')
                        ->numeric()
                        ->step(0.01)
                        ->disabled()->columnSpan(2)
                        ->required()
                        ->dehydrated()
                        ->afterStateHydrated(function ($state, $get, Set $set) {
                            $quantity = $get('quantity');
                            $unitPrice = $get('unit_price');
                            $vat = $get('vat');
                            $total = ($quantity * $unitPrice) + $vat;
                            $set('total_price', round($total, 2));
                        }),
                    ])->columns(10)
                    ->collapsible()
                    ->defaultItems(1),

                    //Orders Summary
                    Section::make('Orders Summary')->schema([
                        Placeholder::make('customer_name')
                            ->label('Customer Name')
                            ->content(function (Get $get) {
                                $customerId = $get('customer_id');
                                if ($customerId) {
                                    $customer = Customer::find($customerId);
                                    return $customer ? $customer->name : 'N/A';
                                }
                                return 'No Customer Selected';
                            }),
                    
                        Placeholder::make('orders_total_amount')
                            ->label('Orders Total Amount')
                            ->content(function (Get $get, Set $set) {
                                $total = 0;
                                if ($repeaters = $get('items')) {
                                    foreach ($repeaters as $repeater) {
                                        $total += floatval($repeater['total_price'] ?? 0);
                                    }
                                }
                                $set('orders_total_amount', $total);
                                return Number::currency($total, 'AED');
                            }),
                    
                        Placeholder::make('vat')
                            ->label('VAT (5%)')
                            ->content(function (Get $get, Set $set) {
                                $total = 0;
                                if ($repeaters = $get('items')) {
                                    foreach ($repeaters as $repeater) {
                                        $total += floatval($repeater['total_price'] ?? 0);
                                    }
                                }
                                $vat = $total * 0.05;
                                $set('vat', $vat);
                                return Number::currency($vat, 'AED');
                            }),
                    
                        Placeholder::make('grand_total')
                            ->label('Grand Total')
                            ->content(function (Get $get, Set $set) {
                                $total = 0;
                                if ($repeaters = $get('items')) {
                                    foreach ($repeaters as $repeater) {
                                        $total += floatval($repeater['total_price'] ?? 0);
                                    }
                                }
                                $vat = $total * 0.05;
                                $grandTotal = $total + $vat;
                                $set('grand_total', $grandTotal);
                                return new \Illuminate\Support\HtmlString('<strong style="color:red;" class="bold-text">' . Number::currency($grandTotal, 'AED') . '</strong>');
                            }),
                    ])->columns(4),
                    
                    Hidden::make('orders_total_amount')->default(0),
                    Hidden::make('vat')->default(0),
                    Hidden::make('grand_total')->default(0),
                ])
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table  
            ->columns([
                Split::make([
                    TextColumn::make('items_in_order')
                    ->label('Items in Order')
                    ->default(function ($record) {
                        // Assuming $record is an Order model instance
                        $orderId = $record->id;
                        
                        // Count the number of items in this order
                        $orderItemCount = OrderItem::where('order_id', $orderId)->count();
                        
                        return $orderItemCount;
                    })->extraAttributes(['style' => "color: red; font-size:18px; font-weight: 600"]),

                    TextColumn::make('customer.name')
                    ->label('Customer Name')
                    ->extraAttributes(['style' => "color: red; font-size:18px; font-weight: 600"]),
                    TextColumn::make('orders_total_amount')
                    ->label('Orders Total Amount')
                    ->extraAttributes(['style' => "color: red; font-size:18px; font-weight: 600"]),
                    TextColumn::make('vat')
                    ->label('Total VAT')
                    ->extraAttributes(['style' => "color: red; font-size:18px; font-weight: 600"]),                    
                    TextColumn::make('grand_total')
                    ->label('Grand Total')
                    ->extraAttributes(['style' => "color: red; font-size:18px; font-weight: 600"]),
                    TextColumn::make('remarks')
                        ->label('Remarks')
                        ->extraAttributes(['style' => "color: red; font-size:18px; font-weight: 600"]),
                    TextColumn::make('user.name')
                        ->label('Sales Person')
                        ->extraAttributes(['style' => "color: red; font-size:18px; font-weight: 600"]),
                    SelectColumn::make('status')
                    ->label('Status')
                    ->options([
                        'new' => 'New',
                        'processing' => 'Processing',
                        'delivered' => 'Delivered',
                        'cancelled' => 'Cancelled',
                    ]),
                ]),
    
                Panel::make([
                    Split::make([
                        TextColumn::make('items')
                        ->label('Order ID')
                        ->getStateUsing(function ($record) {
                            $output = '<table style="width: 275%;border: 2px solid #fdde6c; border-radius: 25px;">';
                            $output .=
                            '
                            <h2 style="margin: 10px; font-size: 16px; font-weight:bold;">Order Items</h2>
                            <tr style="width: full; background:#fdde6c; border: 2px solid fdde6c; border-radius: 25px;">
                                <th style="text-align: left; padding: 8px;">Item Name</th>
                                <th style="text-align: left; padding: 8px;">Quantity</th>
                                <th style="text-align: left; padding: 8px;">Unit Price</th>
                                <th style="text-align: left; padding: 8px;">VAT</th>
                                <th style="text-align: left; padding: 8px;">Total</th>
                            </tr>';
                            foreach ($record->items as $item) {
                                $output .=
                                "<tr>
                                    <td style='padding: 8px; border-bottom: 1px solid #ddd;'>{$item->item->name}</td>
                                    <td style='padding: 8px; border-bottom: 1px solid #ddd;'>{$item->quantity}</td>
                                    <td style='padding: 8px; border-bottom: 1px solid #ddd;'>{$item->unit_price}</td>
                                    <td style='padding: 8px; border-bottom: 1px solid #ddd;'>{$item->vat}</td>
                                    <td style='padding: 8px; border-bottom: 1px solid #ddd;'>{$item->total_price}</td>
                                </tr>";
                            }
                            $output .= '</table>';
                            return $output;
                        })
                        ->html(),
                    ]),
                ])->collapsed(true)
            ])->recordAction(null)
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make(),
                ])
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::count();
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return static::getModel()::count() > 0 ? 'success' : 'danger';
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrders::route('/'),
            'create' => Pages\CreateOrder::route('/create'),
            'view' => Pages\ViewOrder::route('/{record}'),
            'edit' => Pages\EditOrder::route('/{record}/edit'),
        ];
    }

    private static function calculateTotals($quantity, callable $get, Set $set): void
    {
        $unitPrice = floatval($get('unit_price'));
        $subtotal = $quantity * $unitPrice;
        $vat = $subtotal * 0.05; // 5% VAT
        // $total = $subtotal + $vat;
        $total = $subtotal;

        $set('vat', round($vat, 2));
        $set('total_price', round($total, 2));
    }
}
