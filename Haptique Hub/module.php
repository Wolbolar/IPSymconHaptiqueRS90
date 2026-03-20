<?php

declare(strict_types=1);
	class HaptiqueHub extends IPSModuleStrict
	{
        public function GetCompatibleParents(): string
        {
            return json_encode([
                'type' => 'require',
                'moduleIDs' => ['{EACDE370-4FB4-0B48-DA4F-C32526B7C652}']
            ]);
        }
        public function Create(): void
        {
			//Never delete this line!
			parent::Create();

            //we will wait until the kernel is ready
            $this->RegisterMessage(0, IPS_KERNELMESSAGE);
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
		}

		public function Destroy(): void
        {
			//Never delete this line!
			parent::Destroy();
		}

		public function ApplyChanges(): void
        {
			//Never delete this line!
			parent::ApplyChanges();
		}

        public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
        {
            //Never delete this line!
            parent::MessageSink($TimeStamp, $SenderID, $Message, $Data);

            if ($Message == IPS_KERNELMESSAGE && $Data[0] == KR_READY) {
                $this->SendDebug("MessageSink", "🔄 Kernel Ready", 0);
            }

            if ($Message == IPS_KERNELSTARTED) {
                $this->SendDebug("MessageSink", "🔄 Kernel Started", 0);
            }

            if ($Message == IM_CHANGESTATUS && $Data[0] == IS_ACTIVE) {
                $this->SendDebug("MessageSink", "🔄 Instanz aktiv", 0);
            }
        }

		public function Send()
		{
			$this->SendDataToParent(json_encode(['DataID' => '{7DEF4465-6841-1542-BC31-3A0C47DB5855}']));
		}

		public function ReceiveData($JSONString): string
        {
			$data = json_decode($JSONString);
			return '';
		}
	}